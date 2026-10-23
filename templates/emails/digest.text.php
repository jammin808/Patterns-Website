<?php
/**
 * The digest's written text part (docs/NEWS.md §14.8): the same words as digest.php and _digest-footer.php, one
 * article a block (category, headline, standfirst, link), every link and the unsubscribe address written out.
 * Plain text, so nothing is HTML-escaped.
 *
 * @var bool $test @var bool $sample
 * @var array{start: string, end: string}|null $window
 * @var list<array{category_name: string, title: string, dek: string, url: string}> $articles
 * @var int $leftOut @var string $newsUrl
 * @var array{email: string, source: string, date: string}|null $who
 * @var string $unsubscribeUrl @var string $mailto @var string $mailtoSubject "Unsubscribe" and this message's token
 * @var string $privacyUrl @var string $publisher @var string $siteName
 */
// What the subscriber did, in the second person; :date (confirmed_at, London) ends the sentence.
$how = [
    'page' => 'signed up for it on our website and confirmed it',
    'account' => 'ticked the box for it on your account page and confirmed it',
    'registration' => 'ticked the box for it when you created your account and confirmed it',
];
$lines = [];
if ($test) {
    $lines[] = 'A test of the next digest, sent from the desk. Its unsubscribe links change nothing.'
        . ($sample ? ' No article has gone live since the last digest yet, so these are the newest articles instead.' : '');
    $lines[] = '';
}
$lines[] = 'The week in Industry Updates';
if ($window !== null) {
    $lines[] = 'Every article that went live between ' . $window['start'] . ' and ' . $window['end'] . ', the newest first.';
}
foreach ($articles as $article) {
    $lines[] = '';
    if ($article['category_name'] !== '') {
        $lines[] = $article['category_name'];
    }
    $lines[] = $article['title'];
    $lines[] = $article['dek'];
    $lines[] = $article['url'];
}
if ($leftOut > 0) {
    $lines[] = '';
    $lines[] = 'And ' . $leftOut . ' more this week on Industry Updates: ' . $newsUrl;
}
$lines[] = '';
$lines[] = 'Open Industry Updates: ' . $newsUrl;
$lines[] = '';
$lines[] = '--';
$lines[] = $who === null
    ? 'You get this test because you sent it from the Industry Updates desk.'
    : 'You get this weekly digest at ' . $who['email'] . ' because you ' . ($how[$who['source']] ?? $how['page']) . ' on ' . $who['date'] . '.';
$lines[] = 'To stop it, open this link and press Unsubscribe: ' . $unsubscribeUrl;
if ($mailto !== '') {
    $lines[] = 'Or email ' . $mailto . ' with the subject: ' . $mailtoSubject;
}
$lines[] = 'From ' . $siteName . ', run by ' . $publisher . ', United Kingdom. How we use your address: our privacy notice, ' . $privacyUrl;
echo implode("\n", $lines), "\n";
