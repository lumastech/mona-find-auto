<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

/**
 * Cancelling an invitation nobody has accepted yet.
 */
class RevokeInvitationRequest extends ReasonedRequest {}
