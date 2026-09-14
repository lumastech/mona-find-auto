<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

/**
 * Suspending a staff account. The reason reaches the person as well as the audit row — somebody locked out of the console needs to know whether they have been fired or their laptop has been stolen.
 */
class DeactivateStaffRequest extends ReasonedRequest {}
