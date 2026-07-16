<?php

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Services\JwtAccessTokenService;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;

class PublicIdUserProvider extends EloquentUserProvider
{
    public function __construct(
        Hasher $hasher,
        string $model,
        private readonly JwtAccessTokenService $accessTokens,
        private readonly Request $request,
    ) {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier)
    {
        $model = $this->createModel();
        $user = $model->newQueryWithoutScopes()->where('public_id', $identifier)->first();
        $token = $this->request->bearerToken();
        if ($user === null || $token === null) {
            return $user;
        }

        try {
            $validated = $this->accessTokens->validate($token);
        } catch (IdentityException) {
            return null;
        }

        return $validated->is($user) ? $user : null;
    }
}
