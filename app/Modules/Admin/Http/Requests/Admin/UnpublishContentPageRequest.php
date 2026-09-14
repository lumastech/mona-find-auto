<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

/**
 * Taking a page down. The reason is recorded against the page: a page that disappeared is a question somebody will ask.
 */
class UnpublishContentPageRequest extends ReasonedRequest {}
