<?php

namespace App\Services;

use App\Exceptions\GoogleAccountException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleAccountService
{
    public function resolve(GoogleUser $identity, ?User $linkUser): User
    {
        $id = $identity->getId();
        $email = $identity->getEmail();
        if (! is_string($id) || $id === '' || strlen($id) > 255
            || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255
            || ($identity->user['email_verified'] ?? false) !== true) {
            throw new GoogleAccountException('invalid_google');
        }
        $email = strtolower(trim($email));
        try {
            return $this->resolveTransaction($identity, $id, $email, $linkUser);
        } catch (UniqueConstraintViolationException) {
            return $this->resolveTransaction($identity, $id, $email, $linkUser);
        }
    }

    private function resolveTransaction(GoogleUser $identity, string $id, string $email, ?User $linkUser): User
    {
        return DB::transaction(function () use ($identity, $id, $email, $linkUser): User {
            $linked = User::query()->where('google_id', $id)->lockForUpdate()->first();
            if ($linkUser) {
                $user = User::query()->lockForUpdate()->findOrFail($linkUser->id);
                if (strtolower($user->email) !== $email || ($linked && $linked->id !== $user->id)
                    || ($user->google_id && $user->google_id !== $id)) {
                    throw new GoogleAccountException('link_conflict');
                }
                $user->forceFill(['google_id' => $id, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

                return $user;
            }
            if ($linked) {
                // Google subject is stable; do not silently replace the canonical account email.
                return $linked;
            }
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->exists()) {
                throw new GoogleAccountException('account_exists');
            }
            $name = $identity->getName();
            $user = new User(['name' => is_string($name) && trim($name) !== '' ? mb_substr($name, 0, 120) : 'Usuário', 'email' => $email]);
            $user->forceFill(['google_id' => $id, 'password' => null, 'email_verified_at' => now()])->save();

            return $user;
        });
    }
}
