<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Authorization\AuthoritativeAuthReads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Statamic\Auth\Eloquent\RoleRepository;
use Statamic\Auth\Eloquent\Roles;
use Statamic\Auth\Eloquent\UserRepository;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Auth\UserRepository as Repository;
use Statamic\Facades\User;

beforeEach(function () {
    config(['database.connections.actor_primary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'sticky' => false],
        'statamic.users.database' => 'actor_primary']);
    $this->connection = DB::connection('actor_primary');
    $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, status TEXT)');
    $this->connection->table('users')->insert(['id' => 1, 'email' => 'actor@example.test', 'status' => 'inactive']);
    $this->replica = new PDO('sqlite::memory:');
    $this->replica->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, status TEXT)');
    $this->replica->exec("INSERT INTO users VALUES (1, 'actor@example.test', 'active')");
    $this->connection->setReadPdo($this->replica);
    $this->app->bind(UserContract::class, Statamic\Auth\Eloquent\User::class);
    $this->app->instance(Repository::class, new UserRepository(['model' => AuthoritativeActorModel::class]));
    User::clearResolvedInstance(Repository::class);
});

afterEach(function () {
    DB::purge('actor_primary');
});

it('refuses an actor disabled on the primary even when the replica still allows access', function () {
    expect(User::find('1')->get('status'))->toBe('active');
    $context = new ExecutionContext('1', 'grant', 'default', 'operator', 'http', 'call', hrtime(true) + 10_000_000_000);
    expect(fn () => app(ActorScope::class)->run($context, fn () => throw new LogicException('Must not enter handler')))
        ->toThrow(AuthorizationException::class);
    expect($this->connection->getRawReadPdo())->toBe($this->replica);
});

it('restores lazy replica routing after nested authorization and failure', function () {
    $lazy = fn () => $this->replica;
    $this->connection->setReadPdo($lazy);
    $reads = app(AuthoritativeAuthReads::class);
    expect(fn () => $reads->run(function () use ($reads) {
        expect($this->connection->table('users')->value('status'))->toBe('inactive');
        $reads->run(fn () => expect($this->connection->table('users')->value('status'))->toBe('inactive'));
        expect($this->connection->table('users')->value('status'))->toBe('inactive');
        throw new RuntimeException('Policy failed');
    }))->toThrow(RuntimeException::class, 'Policy failed');
    expect($this->connection->getRawReadPdo())->toBe($lazy)
        ->and($this->connection->table('users')->value('status'))->toBe('active');
});

it('reads native role pivots and permissions from their separate primary connection', function () {
    config(['database.connections.roles_primary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'sticky' => false],
        'statamic.users.database' => 'roles_primary', 'statamic.users.tables.roles' => 'roles']);
    $roles = DB::connection('roles_primary');
    $replica = new PDO('sqlite::memory:');
    foreach ([$roles->getPdo(), $replica] as $pdo) {
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, handle TEXT, title TEXT, permissions TEXT, preferences TEXT)');
        $pdo->exec('CREATE TABLE role_user (id INTEGER PRIMARY KEY, user_id INTEGER, role_id INTEGER)');
        $pdo->exec("INSERT INTO roles VALUES (1, 'editor', 'Editor', '[\"edit target entries\"]', '[]')");
        $pdo->exec('INSERT INTO role_user VALUES (1, 1, 1)');
    }
    $roles->table('roles')->update(['permissions' => '[]']);
    $roles->setReadPdo($replica);
    try {
        app(AuthoritativeAuthReads::class)->run(function () {
            $actor = User::find('1');
            $pivots = (new Roles($actor))->all();
            expect($pivots)->toHaveCount(1);
            $role = (new RoleRepository)->find('editor');
            expect($role->permissions()->all())->toBe([]);
        });
        expect($roles->getRawReadPdo())->toBe($replica)
            ->and($this->connection->getRawReadPdo())->toBe($this->replica);
    } finally {
        DB::purge('roles_primary');
    }
});

it('refuses transaction snapshots and preserves their ownership', function (bool $raw) {
    $raw ? $this->connection->getPdo()->beginTransaction() : $this->connection->beginTransaction();
    try {
        expect(fn () => app(AuthoritativeAuthReads::class)->run(fn () => throw new RuntimeException('Must not enter')))
            ->toThrow(LogicException::class, 'Native SQL authorization requires committed reads outside a transaction.');
        expect($this->connection->getPdo()->inTransaction())->toBeTrue()
            ->and($this->connection->getRawReadPdo())->toBe($this->replica);
    } finally {
        $raw ? $this->connection->getPdo()->rollBack() : $this->connection->rollBack();
    }
})->with([true, false]);

class AuthoritativeActorModel extends Model
{
    protected $connection = 'actor_primary';

    protected $table = 'users';
}
