<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\View;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminSfElement;

/**
 * The unavailable state of a storage page (`larena-storage::admin._state`), prepared before the view prints it
 * (developer remark 3', stage 3): the Framework alert of the state, in the section's words for it, drawn by the
 * shared element Document renderer. A page that names the state in its own words passes `stateNotice` with its alert.
 */
final readonly class StorageStateViewComposer
{
    public function __construct(private Translator $translator)
    {
    }

    public function compose(View $view): void
    {
        $status = $view->getData()['status'] ?? 'ok';
        $status = is_string($status) ? $status : 'ok';
        $copy = fn (string $key): string => (string) $this->translator->get('larena-storage::admin.section.states.'.$status.'_'.$key);
        $view->with('stateAlert', $status === 'ok' ? null : (new AdminElementDocumentRenderer())->tree(AdminSfElement::alert($copy('text'), 'danger', $copy('title'))));
    }
}
