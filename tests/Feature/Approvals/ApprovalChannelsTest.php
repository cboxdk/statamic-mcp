<?php

declare(strict_types=1);

use Cbox\Id\Client\Contracts\BackchannelAuthentication;
use Cbox\Id\Client\Testing\FakeBackchannelAuthentication;
use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Relay;
use Cbox\Relay\Client\RelayServiceProvider;
use Cbox\Relay\Client\Testing\FakeRelay;
use Cboxdk\StatamicMcp\Approvals\ApprovalChannels;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalAuthority;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\PendingApproval;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * Approval channels: notifiers only tell the person and never carry the call's
 * arguments; an authority's answer is recorded through the same approve/reject
 * as the Control Panel, so the owner binding and the single use still hold; a
 * failing channel never fails the tool call.
 */

final class ChTestNotifier implements ApprovalNotifier
{
    /** @var list<PendingApproval> */
    public static array $seen = [];

    public static bool $throw = false;

    public function notify(PendingApproval $approval): void
    {
        if (self::$throw) {
            throw new RuntimeException('notifier down');
        }
        self::$seen[] = $approval;
    }
}

final class ChTestAuthority implements ApprovalAuthority
{
    /** @var array<string, bool|null> */
    public static array $answers = [];

    public static ?bool $next = null;

    public function start(PendingApproval $approval): void
    {
        self::$answers[$approval->id] = null;
    }

    public function settle(string $approvalId): ?bool
    {
        return array_key_exists($approvalId, self::$answers) ? self::$next : null;
    }
}

beforeEach(function () {
    $this->chDir = sys_get_temp_dir() . '/mcp-channels-' . bin2hex(random_bytes(8));
    config([
        'statamic.mcp.storage.operations_path' => $this->chDir,
        'statamic.mcp.security.force_web_mode' => true,
        'statamic.mcp.confirmation.enabled' => true,
        'statamic.users.elevated_sessions_enabled' => true,
        'statamic.editions.pro' => true,
        'mail.from.address' => 'cms@example.test',
    ]);
    ChTestNotifier::$seen = [];
    ChTestNotifier::$throw = false;
    ChTestAuthority::$answers = [];
    ChTestAuthority::$next = null;

    Collection::make('ch_pages')->save();
    Blueprint::make('ch_pages')->setNamespace('collections.ch_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    Entry::make()->id('ch-one')->collection('ch_pages')->slug('ch-one')->data(['title' => 'Secret launch plan'])->save();

    Role::make()->handle('ch_editor')->permissions([
        'access cp', 'view mcp dashboard', 'view ch_pages entries', 'edit ch_pages entries', 'delete ch_pages entries',
    ])->save();
    $this->chOwner = tap(User::make()->id('ch-owner')->email('owner@example.test')->set('cbox_id', 'usr_owner')->assignRole('ch_editor'))->save();
    $this->actingAs($this->chOwner);
});

afterEach(function () {
    File::deleteDirectory($this->chDir);
});

function chDelete(array $extra = []): array
{
    return (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ch_pages', 'id' => 'ch-one', 'revision_message' => 'do-not-leak'] + $extra);
}

it('mails the owner through the site mailer by default, without the call arguments', function () {
    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
        $sent[] = $event->message;
    });

    $asked = chDelete();

    expect($asked['data']['code'])->toBe('APPROVAL_REQUIRED')->and($sent)->toHaveCount(1);
    $mail = $sent[0];
    expect($mail->getTo()[0]->getAddress())->toBe('owner@example.test')
        ->and($mail->getSubject())->toContain("Delete entry 'ch-one'")
        ->and($mail->getTextBody())->toContain(cp_route('statamic-mcp.approvals.index'))
        ->and($mail->getTextBody())->toContain('cboxrelay.com')
        ->and($mail->getTextBody() . $mail->getHtmlBody())->not->toContain('do-not-leak')
        ->and($mail->getTextBody() . $mail->getHtmlBody())->not->toContain($asked['data']['approval_id']);
});

it('leaves the promotion out when turned off, and sends nothing with channels none', function () {
    config(['statamic.mcp.approvals.promote_cbox' => false]);
    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
        $sent[] = $event->message;
    });
    chDelete();
    expect($sent[0]->getTextBody())->not->toContain('cboxrelay.com');

    config(['statamic.mcp.approvals.channels' => 'none']);
    chDelete();
    expect($sent)->toHaveCount(1);
});

it('still asks for approval when a notifier throws', function () {
    config(['statamic.mcp.approvals.channels' => ChTestNotifier::class]);
    ChTestNotifier::$throw = true;

    expect(chDelete()['data']['code'])->toBe('APPROVAL_REQUIRED');
});

it('hands a custom notifier the description and link, never the arguments', function () {
    config(['statamic.mcp.approvals.channels' => ChTestNotifier::class]);
    $asked = chDelete();

    expect(ChTestNotifier::$seen)->toHaveCount(1);
    $seen = ChTestNotifier::$seen[0];
    expect($seen->id)->toBe($asked['data']['approval_id'])
        ->and($seen->userId)->toBe('ch-owner')
        ->and($seen->approveUrl)->toBe(cp_route('statamic-mcp.approvals.index'))
        ->and(json_encode((array) $seen))->not->toContain('do-not-leak');
});

it('refuses a channel that is neither a notifier nor an authority', function () {
    config(['statamic.mcp.approvals.channels' => stdClass::class]);

    expect(fn () => app(ApprovalChannels::class))->toThrow(InvalidArgumentException::class);
});

it('runs the call once when an authority approves, and not when it rejects', function () {
    config(['statamic.mcp.approvals.channels' => ChTestAuthority::class]);
    $id = chDelete()['data']['approval_id'];

    expect(chDelete(['approval_id' => $id])['data']['code'])->toBe('APPROVAL_PENDING');

    ChTestAuthority::$next = true;
    $ran = chDelete(['approval_id' => $id]);
    expect($ran['success'])->toBeTrue()->and(Entry::find('ch-one'))->toBeNull();

    // Spent: repeating the call replays the answer; it does not write again.
    expect(chDelete(['approval_id' => $id])['data'])->toHaveKey('approval_replay', true);
});

it('records an authority rejection, and drops the approval from the page once decided', function () {
    config(['statamic.mcp.approvals.channels' => ChTestAuthority::class]);
    $id = chDelete()['data']['approval_id'];

    $this->get(cp_route('statamic-mcp.approvals.index'))->assertInertia(fn ($page) => $page->has('approvals', 1));

    ChTestAuthority::$next = false;
    $this->get(cp_route('statamic-mcp.approvals.index'))->assertInertia(fn ($page) => $page->has('approvals', 0));
    expect(chDelete(['approval_id' => $id])['data']['code'])->toBe('APPROVAL_REJECTED')
        ->and(Entry::find('ch-one'))->not->toBeNull();
});

it('lets a Control Panel decision stand over a later authority answer', function () {
    config(['statamic.mcp.approvals.channels' => ChTestAuthority::class]);
    $id = chDelete()['data']['approval_id'];

    $this->withSession(['statamic_elevated_session' => now()->timestamp])
        ->postJson(cp_route('statamic-mcp.approvals.reject', ['approval' => $id]))->assertOk();
    ChTestAuthority::$next = true;

    expect(chDelete(['approval_id' => $id])['data']['code'])->toBe('APPROVAL_REJECTED')
        ->and(Entry::find('ch-one'))->not->toBeNull();
});

describe('Cbox Relay', function () {
    beforeEach(function () {
        if (! class_exists(Relay::class)) {
            $this->markTestSkipped('cboxdk/laravel-relay-client is not installed.');
        }
        $this->app->register(RelayServiceProvider::class);
        config(['relay.key' => 'rk_test', 'relay.url' => 'https://relay.test']);
        Cache::flush();
        $this->relay = Cbox\Relay\Client\Facades\Relay::fake();
    });

    it('mails, pushes to the owner\'s browsers and publishes a webhook event, without the arguments', function () {
        $mine = $this->relay->seedPushSubscription(FakeRelay::DEFAULT_PROJECT, 'statamic-mcp:user:ch-owner');
        $this->relay->seedPushSubscription(FakeRelay::DEFAULT_PROJECT, 'statamic-mcp:user:someone-else');

        $asked = chDelete();
        expect($asked['data']['code'])->toBe('APPROVAL_REQUIRED');

        $this->relay->assertMailSent(fn ($mail) => $mail->to === ['owner@example.test']
            && str_contains($mail->subject, "Delete entry 'ch-one'")
            && ! str_contains((string) $mail->text, 'cboxrelay.com')
            && ! str_contains((string) $mail->html . $mail->text, 'do-not-leak'));
        $this->relay->assertPushedTimes(1);
        $this->relay->assertPushed($mine->id, fn ($push) => $push->payload['url'] === cp_route('statamic-mcp.approvals.index'));
        $this->relay->assertPublished('statamic_mcp.approval.requested',
            fn ($event) => $event->payload['approval_id'] === $asked['data']['approval_id']
                && ! str_contains(json_encode($event->payload), 'do-not-leak'));
    });

    it('keeps the project\'s other event types when declaring its own', function () {
        $this->relay->putEventTypes([new EventType('order.paid')]);

        chDelete();

        expect(array_map(fn ($type) => $type->type, $this->relay->listEventTypes()))
            ->toBe(['order.paid', 'statamic_mcp.approval.requested']);
    });

    it('subscribes a browser for the signed-in user only', function () {
        $this->withSession(['statamic_elevated_session' => now()->timestamp])
            ->postJson(cp_route('statamic-mcp.approvals.push'), [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au'],
            ])->assertOk();

        expect(array_map(fn ($s) => $s->ref, $this->relay->listPushSubscriptions()))->toBe(['statamic-mcp:user:ch-owner']);

        $this->postJson(cp_route('statamic-mcp.approvals.push'), [
            'endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au'],
        ])->assertStatus(422);
    });
});

describe('Cbox ID', function () {
    beforeEach(function () {
        if (! interface_exists(BackchannelAuthentication::class)) {
            $this->markTestSkipped('cboxdk/laravel-id-client is not installed.');
        }
        config(['cbox-id-client.client_id' => 'cli_test']);
        Cache::flush();
        $this->backchannel = new FakeBackchannelAuthentication;
        $this->app->instance(BackchannelAuthentication::class, $this->backchannel);
        config(['statamic.mcp.approvals.channels' => 'cbox_id']);
    });

    it('asks the owner on their phone and runs the call once they approve', function () {
        $id = chDelete()['data']['approval_id'];

        $this->backchannel->assertStarted('usr_owner', bindingMessage: strtoupper(substr($id, 0, 6)) . " · Delete entry 'ch-one' in collection 'ch_pages'. This cannot be undone.");

        $this->backchannel->pending();
        expect(chDelete(['approval_id' => $id])['data']['code'])->toBe('APPROVAL_PENDING');

        $this->travel(10)->seconds();
        $this->backchannel->approve();
        expect(chDelete(['approval_id' => $id])['success'])->toBeTrue()
            ->and(Entry::find('ch-one'))->toBeNull();
    });

    it('records a denial on the phone as a rejection', function () {
        $id = chDelete()['data']['approval_id'];
        $this->backchannel->deny();

        expect(chDelete(['approval_id' => $id])['data']['code'])->toBe('APPROVAL_REJECTED')
            ->and(Entry::find('ch-one'))->not->toBeNull();
    });

    it('falls back to the email when the user has no Cbox ID', function () {
        $this->chOwner->remove('cbox_id')->save();
        chDelete();

        $this->backchannel->assertStarted('owner@example.test');
    });
});
