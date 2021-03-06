<?php

namespace Module\Auth\UseCases\SignIn;

use Module\Auth\Models\User;
use Module\Auth\Services\TokenGenerator\ApiTokenGenerator;
use Carbon\CarbonInterval;
use DomainException;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Str;
use Nevadskiy\Tokens\Exceptions\LockoutException;
use Nevadskiy\Tokens\RateLimiter\RateLimiter;

class SignInHandler
{
    /**
     * @var UserProvider
     */
    private $provider;

    /**
     * @var ApiTokenGenerator
     */
    private $generator;

    /**
     * @var RateLimiter
     */
    private $limiter;

    /**
     * Handler constructor.
     *
     * @param UserProvider $provider
     * @param ApiTokenGenerator $generator
     * @param RateLimiter $limiter
     */
    public function __construct(
        UserProvider $provider,
        ApiTokenGenerator $generator,
        RateLimiter $limiter
    )
    {
        $this->provider = $provider;
        $this->generator = $generator;
        $this->limiter = $limiter;
    }

    /**
     * Handle the sign in use case.
     *
     * @param SignInCommand $command
     * @return User
     * @throws LockoutException
     */
    public function handle(SignInCommand $command): User
    {
        return $this->limiter->limit(
            $this->getThrottleKey($command),
            config('auth.sign_in.rate_limiter.max_attempts'),
            CarbonInterval::seconds(config('auth.sign_in.rate_limiter.seconds')),
            function () use ($command) {
                return $this->signIn($command);
            }
        );
    }

    /**
     * Handle the sign in use case.
     *
     * @param SignInCommand $command
     * @return User
     */
    public function signIn(SignInCommand $command): User
    {
        $user = $this->findUser($command);

        if (! $user || ! $this->validateUserCredentials($command, $user)) {
            throw new DomainException('Authentication failed.');
        }

        $this->generateUserApiToken($user);

        event(new Authenticated('api', $user));

        return $user;
    }

    /**
     * Find a user.
     *
     * @param SignInCommand $command
     * @return User|Authenticatable|null
     */
    private function findUser(SignInCommand $command): ?User
    {
        return $this->provider->retrieveByCredentials(['email' => $command->email]);
    }

    /**
     * Validate the user credentials.
     *
     * @param SignInCommand $command
     * @param User $user
     * @return bool
     */
    private function validateUserCredentials(SignInCommand $command, User $user): bool
    {
        return $this->provider->validateCredentials($user, ['password' => $command->password]);
    }

    /**
     * Generate a token for the given user.
     *
     * @param User $user
     */
    private function generateUserApiToken(User $user): void
    {
        $user->update(['api_token' => $this->generator->generate()]);
    }

    /**
     * Get the throttle key for the given request.
     *
     * @param SignInCommand $command
     * @return string
     */
    private function getThrottleKey(SignInCommand $command): string
    {
        return Str::lower("{$command->email}|{$command->ip}");
    }
}
