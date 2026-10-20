<?php /** @var string $name @var string $topicLabel */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">Thanks, <?= e($name) ?> — we have your message</h1>
<p>Your message about <strong><?= e(lcfirst($topicLabel)) ?></strong> has arrived. You will get a reply by email.</p>
<p>If it is about a show happening in the next 48 hours, mention that in a reply to this email so it can be prioritised.</p>
<p>In the meantime, the <a href="<?= e($baseUrl) ?>/support/">support pages</a> and the support assistant may already have the answer.</p>
