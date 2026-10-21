<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\App;
use Psc\Content\Content;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Uploads;

/**
 * Licence requests and licences, for the client who owns them.
 *
 * What can be requested comes from content/licensing/<product>.php only: products whose pricing_status is 'live',
 * and their tiers and extras marked requestable. The form never decides the price, the number of hardware IDs or
 * the product; they are looked up again from the content when the request arrives.
 *
 * A licence is read by its owner or an administrator; anyone else gets a 404. Its files are downloadable by the
 * owner only while the licence is issued (administrators may open them at any time, to check a file before
 * issuing it). Statuses: requested → awaiting_payment → issued, and revoked.
 */
final class LicenceController extends Controller
{
    public const STATUSES = [
        'requested' => 'Requested',
        'awaiting_payment' => 'Awaiting payment',
        'issued' => 'Issued',
        'revoked' => 'Revoked',
    ];

    /** What each status means, in the client's words (licence pages and status emails). */
    public const STATUS_HELP = [
        'requested' => 'We have your request and will email you about the next step.',
        'awaiting_payment' => 'Payment details are in your email. Your licence file appears here once payment has arrived.',
        'issued' => 'Your licence is ready. Download the licence file below and install it on the PC it was issued for.',
        'revoked' => 'This licence is no longer active. If you think this is a mistake, please get in touch.',
    ];

    /** Short reference prefixes per product. */
    private const PREFIX = ['patterns' => 'PT', 'stagewatch' => 'SW', 'agmessage' => 'AG', 'playmap' => 'PM'];

    /** Where each product shows its hardware ID (help text beside the field). */
    private const HARDWARE_ID_HELP = [
        'stagewatch' => 'In StageWatch: Help → License → Plans → Copy.',
    ];

    /** How a product installs its licence file (shown when a licence is issued). */
    public const INSTALL_HELP = [
        'stagewatch' => 'In StageWatch, open Help → License → Install license file, choose the file and restart StageWatch.',
    ];

    public const HARDWARE_ID_PATTERN = '/^[A-Za-z0-9._:-]{6,128}$/';

    public const PACK_QUANTITY = 5;

    private const LIMIT_REQUESTS = [10, 86400];

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ---------------------------------------------------------------------------------------------------------
    // Pages

    /** My licence requests and licences, newest first; an issued licence lists its files. */
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $db = $this->app->db();
        $licences = $db->all('SELECT * FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC', [(int) $user['id']]);
        $files = [];
        $issued = array_values(array_map(static fn (array $l): int => (int) $l['id'], array_filter($licences, static fn (array $l): bool => $l['status'] === 'issued')));
        if ($issued !== []) {
            $rows = $db->all('SELECT id, licence_id, original_name, size FROM licence_files WHERE licence_id IN (' . implode(', ', array_fill(0, count($issued), '?')) . ') ORDER BY id', $issued);
            foreach ($rows as $f) {
                $files[(int) $f['licence_id']][] = $f;
            }
        }
        return $this->portal('portal/licences/index', [
            'licences' => $licences,
            'files' => $files,
            'statuses' => self::STATUSES,
            'products' => DownloadController::productNames(),
            'options' => self::optionNames(),
            'canRequest' => self::liveProducts() !== [],
        ], 'Licences');
    }

    /**
     * Without a valid ?product= and ?tier=, the choice of licences (one product's with ?product=); with both, the
     * request form for that licence, with one hardware ID field per machine the licence covers.
     */
    public function requestForm(Request $request): Response
    {
        $live = self::liveProducts();
        $product = $request->queryString('product');
        $product = isset($live[$product]) ? $product : '';
        $tier = $request->queryString('tier');
        $option = $product !== '' ? (self::options($product)[$tier] ?? null) : null;
        $session = $this->app->session();
        $errors = $this->takeErrors();
        $old = $session->takeOld();

        if ($option === null) {
            $comingSoon = [];
            foreach (DownloadController::productKeys() as $key) {
                $l = Content::licensing($key);
                if ($l !== null && ($l['pricing_status'] ?? '') === 'coming-soon') {
                    $comingSoon[$key] = DownloadController::productName($key);
                }
            }
            $choices = [];
            foreach ($product !== '' ? [$product => $live[$product]] : $live as $key => $name) {
                $choices[$key] = ['name' => $name, 'options' => self::options($key)];
            }
            return $this->portal('portal/licences/choose', [
                'choices' => $choices,
                'comingSoon' => $product === '' ? $comingSoon : [],
                'product' => $product,
                'tierMissing' => $product !== '' && $tier !== '',
            ], 'Request a licence');
        }

        $licensing = Content::licensing($product) ?? [];
        return $this->portal('portal/licences/request', [
            'product' => $product,
            'productName' => $live[$product],
            'tier' => $tier,
            'option' => $option,
            'hardwareFields' => self::hardwareFields((int) $option['hardware_ids']),
            'hardwareHelp' => self::HARDWARE_ID_HELP[$product] ?? 'Copy it from the licence screen in ' . $live[$product] . '.',
            'nonprofit' => self::extra($licensing, 'nonprofit'),
            'pack' => $option['kind'] === 'tier' ? self::extra($licensing, 'rental') : null,
            'packQuantity' => self::PACK_QUANTITY,
            'errors' => $errors,
            'old' => $old,
        ], 'Request ' . $live[$product] . ' ' . $option['name']);
    }

    public function request(Request $request): Response
    {
        $user = $this->currentUser();
        $uid = (int) $user['id'];
        $live = self::liveProducts();
        $product = $request->input('product');
        $tier = $request->input('tier');
        if (!isset($live[$product])) {
            return $this->backWithErrors('/portal/licences/request/', $request, ['product' => 'Choose a product from the list.'], 'Choose a product to request a licence for.');
        }
        $option = self::options($product)[$tier] ?? null;
        if ($option === null) {
            return $this->backWithErrors('/portal/licences/request/?product=' . rawurlencode($product), $request, ['tier' => 'Choose a licence from the list.'], 'Choose which licence you would like.');
        }
        $form = '/portal/licences/request/?product=' . rawurlencode($product) . '&tier=' . rawurlencode($tier) . '#form';
        $licensing = Content::licensing($product) ?? [];
        $errors = [];

        $ids = [];
        $seen = [];
        foreach (self::hardwareFields((int) $option['hardware_ids']) as $field => $label) {
            $value = trim($request->input($field));
            if ($value === '') {
                $errors[$field] = 'Enter the ' . lcfirst($label) . '.';
            } elseif (!preg_match(self::HARDWARE_ID_PATTERN, $value)) {
                $errors[$field] = 'A hardware ID is 6 to 128 letters, numbers, dots, dashes, colons or underscores. Copy it from the app and paste it here.';
            } elseif (isset($seen[strtoupper($value)])) {
                $errors[$field] = 'This is the same ID as above. Each PC has its own hardware ID.';
            } else {
                $seen[strtoupper($value)] = true;
                $ids[] = $value;
            }
        }

        $nonprofit = $request->input('nonprofit');
        $organisation = trim((string) preg_replace('/\s+/u', ' ', self::utf8($request->input('organisation'))));
        if (!in_array($nonprofit, ['', '1'], true) || ($nonprofit === '1' && self::extra($licensing, 'nonprofit') === null)) {
            $errors['nonprofit'] = 'Nonprofit pricing is not available for this licence.';
        } elseif ($nonprofit === '1' && ($organisation === '' || mb_strlen($organisation) > 160)) {
            $errors['organisation'] = 'Enter your organisation’s name (up to 160 characters).';
        }
        $pack = $request->input('pack');
        if (!in_array($pack, ['', '1'], true) || ($pack === '1' && ($option['kind'] !== 'tier' || self::extra($licensing, 'rental') === null))) {
            $errors['pack'] = 'A 5-pack is not available for this licence.';
        }
        $note = trim(str_replace(["\r\n", "\r"], "\n", self::utf8($request->input('note'))));
        $note = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note);
        if (mb_strlen($note) > 2000) {
            $errors['note'] = 'Keep the note under 2,000 characters.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($form, $request, $errors);
        }
        [$limit, $window] = self::LIMIT_REQUESTS;
        if (!$this->app->limiter()->hit('licence:' . $uid, $limit, $window)) {
            return $this->backWithErrors($form, $request, [], 'You have sent ' . $limit . ' licence requests today, which is the daily limit. Please try again tomorrow, or get in touch through the contact page.');
        }

        $now = time();
        $isNonprofit = $nonprofit === '1';
        $quantity = $pack === '1' ? self::PACK_QUANTITY : 1;
        $customerNote = trim(($isNonprofit ? 'Organisation: ' . $organisation . "\n\n" : '') . $note);
        $reference = $this->newReference($product);
        $id = $this->app->db()->insert('licences', [
            'user_id' => $uid,
            'product' => $product,
            'tier' => $tier,
            'status' => 'requested',
            'hardware_ids' => json_encode($ids, JSON_UNESCAPED_SLASHES),
            'nonprofit' => $isNonprofit ? 1 : 0,
            'quantity' => $quantity,
            'customer_note' => $customerNote !== '' ? $customerNote : null,
            'admin_note' => null,
            'reference' => $reference,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Audit::log($this->app, $uid, 'licence.requested', (string) $id, ['reference' => $reference, 'product' => $product, 'tier' => $tier]);

        $data = [
            'reference' => $reference,
            'productName' => $live[$product],
            'optionName' => (string) $option['name'],
            'priceLabel' => (string) $option['price_label'],
            'hardwareIds' => $ids,
            'nonprofit' => $isNonprofit,
            'organisation' => $organisation,
            'quantity' => $quantity,
            'note' => $note,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
        ];
        $adminUrl = $this->app->baseUrl() . '/portal/admin/licences/' . $id . '/';
        foreach (self::adminRecipients($this->app) as $to) {
            $this->app->mailer()->send($to, 'Licence request ' . $reference . ': ' . $live[$product] . ' ' . $option['name'], 'licence-request-admin', $data + ['url' => $adminUrl], (string) $user['email']);
        }
        $licence = ['product' => $product, 'tier' => $tier, 'nonprofit' => $isNonprofit ? 1 : 0, 'quantity' => $quantity];
        $this->app->mailer()->send((string) $user['email'], 'We have your licence request ' . $reference, 'licence-request-receipt', $data + [
            'paymentMode' => $this->paymentMode(),
            'paymentLink' => self::paymentLink($this->app, $licence),
            'url' => $this->app->baseUrl() . '/portal/licences/' . $id . '/',
        ]);

        return $this->redirect('/portal/licences/' . $id . '/', 'success', 'Request sent. Your reference is ' . $reference . ', and we have emailed you a copy.');
    }

    /** One licence: the request, where it has got to, and its files once issued. */
    public function show(Request $request): Response
    {
        $user = $this->currentUser();
        $licence = $this->findVisible($request->attrInt('id'), $user);
        if ($licence === null) {
            return $this->notFound();
        }
        $status = (string) $licence['status'];
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $files = $status === 'issued' || $isAdmin
            ? $this->app->db()->all('SELECT id, original_name, size, created_at FROM licence_files WHERE licence_id = ? ORDER BY id', [(int) $licence['id']])
            : [];
        $product = (string) $licence['product'];
        $waiting = in_array($status, ['requested', 'awaiting_payment'], true);
        return $this->portal('portal/licences/show', [
            'licence' => $licence,
            'productName' => DownloadController::productName($product),
            'optionName' => self::optionName($product, (string) $licence['tier']),
            'hardwareIds' => self::decodeIds($licence['hardware_ids'] ?? null),
            'statuses' => self::STATUSES,
            'statusHelp' => self::STATUS_HELP,
            'files' => $status === 'issued' ? $files : [],
            'hiddenFiles' => $status !== 'issued' ? count($files) : 0,
            'installHelp' => self::INSTALL_HELP[$product] ?? null,
            'paymentLink' => $waiting && $this->paymentMode() === 'links' ? self::paymentLink($this->app, $licence) : null,
            'isAdmin' => $isAdmin,
        ], 'Licence ' . (string) $licence['reference']);
    }

    /** A licence file: the owner while the licence is issued, or an administrator. */
    public function file(Request $request): Response
    {
        $user = $this->currentUser();
        $licence = $this->findVisible($request->attrInt('id'), $user);
        if ($licence === null) {
            return $this->notFound();
        }
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if ($licence['status'] !== 'issued' && !$isAdmin) {
            return $this->notFound();
        }
        $file = $this->app->db()->one('SELECT * FROM licence_files WHERE id = ? AND licence_id = ?', [$request->attrInt('file'), (int) $licence['id']]);
        $path = $file !== null ? self::storedPath($this->app, (string) $file['stored_name']) : null;
        if ($file === null || $path === null) {
            return $this->notFound();
        }
        $isHead = $request->method === 'HEAD';
        // The audit trail records files a client actually received: a HEAD request sends no file.
        if (!$isAdmin && !$isHead) {
            Audit::log($this->app, (int) $user['id'], 'licence.file_downloaded', (string) $licence['id'], ['file' => (int) $file['id']]);
        }
        $response = $isHead ? new Response('', 200) : Response::stream(static function () use ($path): void {
            readfile($path);
        }, 'application/octet-stream');
        foreach (Uploads::downloadHeaders((string) $file['original_name'], (int) filesize($path)) as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Shared with the admin pages and downloads

    /** @return array<string, string> products whose licences can be requested now: key => name */
    public static function liveProducts(): array
    {
        $out = [];
        foreach (DownloadController::productKeys() as $key) {
            $l = Content::licensing($key);
            if ($l !== null && ($l['pricing_status'] ?? '') === 'live' && self::options($key) !== []) {
                $out[$key] = DownloadController::productName($key);
            }
        }
        return $out;
    }

    /**
     * The licences a product offers for request: its requestable tiers, then its requestable extras (an event
     * pass, for example). Each needs at least one hardware ID.
     *
     * @return array<string, array{id: string, kind: string, name: string, price: string|null, launch_price: string|null, price_label: string, price_note: string, summary: string, machines: int, hardware_ids: int}>
     */
    public static function options(string $product): array
    {
        $l = Content::licensing($product);
        if ($l === null) {
            return [];
        }
        $out = [];
        foreach (['tiers' => 'tier', 'extras' => 'extra'] as $list => $kind) {
            foreach (is_array($l[$list] ?? null) ? $l[$list] : [] as $t) {
                $id = is_array($t) ? (string) ($t['id'] ?? '') : '';
                $count = is_array($t) ? (int) ($t['hardware_ids'] ?? 0) : 0;
                if ($id === '' || isset($out[$id]) || ($t['requestable'] ?? false) !== true || $count < 1 || $count > 10 || !preg_match('/^[a-z0-9-]{1,32}$/', $id)) {
                    continue;
                }
                $out[$id] = [
                    'id' => $id,
                    'kind' => $kind,
                    'name' => (string) ($t['name'] ?? $t['title'] ?? $id),
                    'price' => isset($t['price']) && is_numeric($t['price']) ? (string) $t['price'] : null,
                    'launch_price' => Content::launchPrice($t),
                    'price_label' => (string) ($t['price_label'] ?? ''),
                    'price_note' => (string) ($t['price_note'] ?? ''),
                    'summary' => (string) ($t['summary'] ?? $t['text'] ?? ''),
                    'machines' => $count,
                    'hardware_ids' => $count,
                ];
            }
        }
        return $out;
    }

    /** A tier's or extra's display name, whether or not it can still be requested. */
    public static function optionName(string $product, string $tier): string
    {
        return self::optionNames()[$product][$tier] ?? ucfirst(str_replace(['-', '_'], ' ', $tier));
    }

    /** @return array<string, array<string, string>> product => tier id => name, for every tier and extra */
    public static function optionNames(): array
    {
        $out = [];
        foreach (DownloadController::productKeys() as $key) {
            $l = Content::licensing($key);
            foreach (['tiers', 'extras'] as $list) {
                foreach (is_array($l[$list] ?? null) ? $l[$list] : [] as $t) {
                    if (is_array($t) && isset($t['id']) && is_string($t['id'])) {
                        $out[$key][$t['id']] ??= (string) ($t['name'] ?? $t['title'] ?? $t['id']);
                    }
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function decodeIds(mixed $json): array
    {
        $ids = is_string($json) ? json_decode($json, true) : null;
        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /** @return list<string> where licence requests are sent: mail.admin_to, else app.admin_emails */
    public static function adminRecipients(App $app): array
    {
        $to = $app->config->string('mail.admin_to');
        return $to !== '' ? [$to] : array_values(array_filter($app->config->list('app.admin_emails'), 'is_string'));
    }

    /**
     * The configured payment link for a licence ("<product>.<tier>" in licensing.payment_links), when it applies:
     * only http(s) links, and never for nonprofit or 5-pack requests, whose price differs from the standard link.
     *
     * @param array<string, mixed> $licence
     */
    public static function paymentLink(App $app, array $licence): ?string
    {
        if ($app->config->string('licensing.payment_mode', 'invoice') !== 'links' || (int) ($licence['nonprofit'] ?? 0) === 1 || (int) ($licence['quantity'] ?? 1) !== 1) {
            return null;
        }
        $links = $app->config->get('licensing.payment_links', []);
        $link = is_array($links) ? ($links[$licence['product'] . '.' . $licence['tier']] ?? null) : null;
        return is_string($link) && preg_match('#^https?://[^\s"<>]+$#i', $link) ? $link : null;
    }

    /** The absolute path of a stored licence file, when its stored name is one this site made and the file exists. */
    public static function storedPath(App $app, string $stored): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(dat|lic|txt)$/', $stored)) {
            return null;
        }
        $path = $app->storage->path('licences/' . $stored);
        return is_file($path) ? $path : null;
    }

    /** @return array<string, string> field name => label, one per machine */
    public static function hardwareFields(int $count): array
    {
        if ($count <= 1) {
            return ['hardware_id_1' => 'Hardware ID'];
        }
        if ($count === 2) {
            return ['hardware_id_1' => 'Main PC hardware ID', 'hardware_id_2' => 'Backup PC hardware ID'];
        }
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out['hardware_id_' . $i] = 'PC ' . $i . ' hardware ID';
        }
        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Licence pages need the auth middleware');
        }
        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null the licence when this account may see it
     */
    private function findVisible(int $id, array $user): ?array
    {
        $licence = $id > 0 ? $this->app->db()->one('SELECT * FROM licences WHERE id = ?', [$id]) : null;
        if ($licence === null) {
            return null;
        }
        if ((int) $licence['user_id'] !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
            return null;
        }
        return $licence;
    }

    /**
     * @param array<string, mixed> $licensing
     * @return array<string, mixed>|null
     */
    private static function extra(array $licensing, string $id): ?array
    {
        foreach (is_array($licensing['extras'] ?? null) ? $licensing['extras'] : [] as $x) {
            if (is_array($x) && ($x['id'] ?? null) === $id) {
                return $x;
            }
        }
        return null;
    }

    private function paymentMode(): string
    {
        return $this->app->config->string('licensing.payment_mode', 'invoice') === 'links' ? 'links' : 'invoice';
    }

    /** "SW-" and six random base32 characters, unique among licences. */
    private function newReference(string $product): string
    {
        $prefix = self::PREFIX[$product] ?? 'LC';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            foreach (str_split(random_bytes(6)) as $byte) {
                $code .= self::BASE32[ord($byte) & 31];
            }
            $reference = $prefix . '-' . $code;
            if ($this->app->db()->value('SELECT 1 FROM licences WHERE reference = ?', [$reference]) === null) {
                return $reference;
            }
        }
        throw new \RuntimeException('Could not make a unique licence reference');
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }
}
