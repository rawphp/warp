<?php

declare(strict_types=1);

use RawPHP\Warp\Support\ObjectAccess;

it('sets a protected property on a foreign object', function () {
    $target = new class
    {
        protected string $app = 'base';

        public function app(): string
        {
            return $this->app;
        }
    };

    ObjectAccess::set($target, 'app', 'sandbox');

    expect($target->app())->toBe('sandbox');
});

it('reads and writes via bound closures', function () {
    $target = new class
    {
        protected array $drivers = ['a' => 1];

        protected string $app = 'base';
    };

    $drivers = ObjectAccess::read($target, fn () => $this->drivers);

    expect($drivers)->toBe(['a' => 1]);

    ObjectAccess::write($target, function (): void {
        $this->app = 'sandbox';
        $this->drivers = [];
    });

    expect(ObjectAccess::read($target, fn () => $this->app))->toBe('sandbox')
        ->and(ObjectAccess::read($target, fn () => $this->drivers))->toBe([]);
});
