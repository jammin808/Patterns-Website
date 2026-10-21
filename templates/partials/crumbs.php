<?php /** @var list<array{name: string, path: string}> $crumbs */ ?>
<nav class="c-crumbs" aria-label="Breadcrumb"><ol>
<?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
    <li><a href="<?= e($c['path']) ?>"<?= $last ? ' aria-current="page"' : '' ?>><?= e($c['name']) ?></a></li>
<?php endforeach; ?>
</ol></nav>
