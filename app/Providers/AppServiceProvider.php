<?php

namespace App\Providers;

use App\Events\MessageSent;
use App\Listeners\SendMessageNotification;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Conversation;
use App\Models\Message;
use App\Policies\ContractPolicy;
use App\Policies\ContractTemplatePolicy;
use App\Policies\ConversationPolicy;
use App\Policies\MessagePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends AuthServiceProvider
{
    /**
     * The model-to-policy map for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Contract::class => ContractPolicy::class,
        ContractTemplate::class => ContractTemplatePolicy::class,
        Conversation::class => ConversationPolicy::class,
        Message::class => MessagePolicy::class,
    ];

    /**
     * The event-to-listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        MessageSent::class => [SendMessageNotification::class],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        $this->registerPolicies();

        // Meeting calls are counted per person (account or browser session), not per address: a whole office
        // behind one NAT must not share a bucket. Each route has its own limiter so counters never mix.
        foreach (['enter' => 30, 'poll' => 180, 'join' => 30, 'ice' => 30, 'signal' => 600, 'state' => 120, 'leave' => 30, 'host' => 120] as $name => $perMinute) {
            RateLimiter::for('meeting-'.$name, function (Request $request) use ($perMinute) {
                $who = $request->user()?->id ? 'u'.$request->user()->id : ($request->hasSession() ? 's'.$request->session()->getId() : 'ip'.$request->ip());

                return Limit::perMinute($perMinute)->by($who.'|'.$request->route('roomKey'));
            });
        }
    }
}
