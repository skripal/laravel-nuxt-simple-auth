<?php

namespace Module\Auth\Tests\Unit\UseCases;

use Module\Auth\Services\TokenGenerator\ApiTokenGenerator;
use Module\Auth\UseCases\SignIn\SignInCommand;
use Module\Auth\UseCases\SignIn\SignInHandler;
use Module\Auth\Models\User;
use Carbon\CarbonInterval;
use DateInterval;
use DomainException;
use Illuminate\Contracts\Hashing\Hasher;
use Module\Auth\Tests\DatabaseTestCase;
use Nevadskiy\Tokens\RateLimiter\RateLimiter;

/**
 * @see SignInHandler
 */
class SignInHandlerTest extends DatabaseTestCase
{
    /** @test */
    public function it_retrieves_user_by_credentials(): void
    {
        $user = factory(User::class)->create([
            'email' => 'user@mail.com',
            'password' => 'database.password',
        ]);

        $hasher = $this->mock(Hasher::class)
            ->shouldReceive('check')
            ->once()
            ->with('request.password', 'database.password')
            ->andReturn(true)
            ->getMock();

        $this->app->instance('hash', $hasher);

        $command = new SignInCommand('user@mail.com', 'request.password', 'testing-ip');

        $authUser = app(SignInHandler::class)->handle($command);

        $this->assertTrue($authUser->is($user));
    }

    /** @test */
    public function it_generates_a_api_token_for_the_found_user(): void
    {
        factory(User::class)->create([
            'email' => 'user@mail.com',
            'password' => 'database.password',
        ]);

        $hasher = $this->mock(Hasher::class)
            ->shouldReceive('check')
            ->once()
            ->with('request.password', 'database.password')
            ->andReturn(true)
            ->getMock();

        $this->app->instance('hash', $hasher);

        $this->mock(ApiTokenGenerator::class)
            ->shouldReceive('generate')
            ->once()
            ->andReturn('simple-api-token');

        $command = new SignInCommand('user@mail.com', 'request.password', 'testing-ip');

        $user = app(SignInHandler::class)->handle($command);

        $this->assertEquals('simple-api-token', $user->fresh()->api_token);
    }

    /** @test */
    public function it_throws_an_exception_if_user_is_not_found(): void
    {
        $spy = $this->spy(ApiTokenGenerator::class);

        try {
            app(SignInHandler::class)->handle(new SignInCommand('user@mail.com', 'password', 'testing-ip'));
            $this->fail('Exception was not thrown but should.');
        } catch (DomainException $e) {
            $spy->shouldNotHaveReceived('generate');
        }
    }

    /** @test */
    public function it_throws_an_exception_if_password_is_not_correct(): void
    {
        factory(User::class)->create([
            'email' => 'user@mail.com',
            'password' => 'database.password',
        ]);

        $hasher = $this->mock(Hasher::class)
            ->shouldReceive('check')
            ->once()
            ->with('password', 'database.password')
            ->andReturn(false)
            ->getMock();

        $this->app->instance('hash', $hasher);

        $command = new SignInCommand('user@mail.com', 'password', 'testing-ip');

        $spy = $this->spy(ApiTokenGenerator::class);

        try {
            app(SignInHandler::class)->handle($command);
            $this->fail('Exception was not thrown but should.');
        } catch (DomainException $e) {
            $spy->shouldNotHaveReceived('generate');
        }
    }

    /** @test */
    public function it_uses_rate_limiter_for_sign_in_process(): void
    {
        $this->freezeTime();

        config(['auth.sign_in.rate_limiter.max_attempts' => 3]);
        config(['auth.sign_in.rate_limiter.seconds' => 40]);

        $user = factory(User::class)->create([
            'email' => 'user@mail.com',
            'password' => 'database.password',
        ]);

        $hasher = $this->app->instance('hash', $this->mock(Hasher::class));
        $hasher->shouldReceive('check')->with('password', 'database.password')->andReturn(true);

        $this->mock(RateLimiter::class)
            ->shouldReceive('limit')
            ->andReturnUsing(function (string $key, int $attempts, DateInterval $timeout, callable $callback) {
                $this->assertEquals('user@mail.com|testing-ip', $key);
                $this->assertEquals(3, $attempts);
                $this->assertEquals(CarbonInterval::second(40), $timeout);
                return $callback();
            });

        $this->assertTrue($user->is(app(SignInHandler::class)->handle(new SignInCommand('user@mail.com', 'password', 'testing-ip'))));
    }
}
