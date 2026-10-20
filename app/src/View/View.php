<?php

declare(strict_types=1);

namespace Psc\View;

/**
 * PHP templates under templates/. A template is included with its data as local variables and $this bound to the
 * view, so it can call $this->partial(), $this->push() and $this->stack(). Output is escaped by e() in templates;
 * nothing a visitor typed is ever echoed raw.
 */
final class View
{
    /** @var array<string, list<string>> */
    private array $stacks = [];

    /** @var array<string, mixed> data every template sees */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    public function exists(string $template): bool
    {
        return is_file($this->file($template));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->file($template);
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        $vars = $data + $this->shared;
        $level = ob_get_level();
        ob_start();
        try {
            (function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })->call($this, $file, $vars);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->render('partials/' . $template, $data);
    }

    /** Renders a body template, then wraps it in a layout that receives it as $content. */
    public function page(string $template, array $data = [], string $layout = 'layouts/base'): string
    {
        $content = $this->render($template, $data);
        return $this->render($layout, ['content' => $content] + $data);
    }

    /** Adds markup to a named stack (e.g. 'scripts', 'head') rendered by the layout. */
    public function push(string $stack, string $html): void
    {
        if (!in_array($html, $this->stacks[$stack] ?? [], true)) {
            $this->stacks[$stack][] = $html;
        }
    }

    public function stack(string $stack): string
    {
        return implode("\n", $this->stacks[$stack] ?? []);
    }

    private function file(string $template): string
    {
        $template = str_replace(['..', '\\'], ['', '/'], $template);
        return $this->dir . '/' . ltrim($template, '/') . '.php';
    }
}
