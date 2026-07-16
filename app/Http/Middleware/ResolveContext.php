<?php

namespace App\Http\Middleware;

/**
 * @deprecated Use the identity.context middleware alias. Kept so older route
 * declarations inherit the authenticated, server-validated behavior.
 */
class ResolveContext extends AuthenticatedContext {}
