<?php

namespace Rider\Core;

/**
 * Minimal PHP-template view renderer — server-rendered pages progressively
 * enhanced with JS, per planning/00-portfolio/shared-architecture.md's
 * frontend stack decision (no JS framework). Templates live in
 * src/Views/*.php and are plain PHP files using short echo tags; this
 * class only handles wrapping them in the shared layout. Ported unchanged
 * from laundry.co.ke's reference implementation (see
 * planning/00-portfolio/ui-implementation-plan.md §2).
 */
final class View
{
    public static function render(string $template, array $data = []): void
    {
        // Every page gets the current session user available as $currentUser
        // (for layout.php's nav) without every controller having to thread
        // it through explicitly — see src/Core/Auth.php.
        $data['currentUser'] = $data['currentUser'] ?? Auth::currentUser();

        extract($data);
        $viewFile = __DIR__ . '/../Views/' . $template . '.php';

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layout.php';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
