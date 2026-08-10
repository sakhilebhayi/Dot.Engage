<?php

namespace App\Providers;

use App\Events\ContractShared;
use App\Events\ContractSigned;
use App\Events\MessageSent;
use App\Events\VideoSessionEnded;
use App\Listeners\LogVideoSession;
use App\Listeners\NotifyContractShared;
use App\Listeners\NotifyContractSigned;
use App\Listeners\SendMessageNotification;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\VideoSession;
use App\Policies\ContractPolicy;
use App\Policies\ContractTemplatePolicy;
use App\Policies\ConversationPolicy;
use App\Policies\MessagePolicy;
use App\Policies\VideoSessionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;

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
        VideoSession::class => VideoSessionPolicy::class,
    ];

    /**
     * The event-to-listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        MessageSent::class => [SendMessageNotification::class],
        ContractShared::class => [NotifyContractShared::class],
        ContractSigned::class => [NotifyContractSigned::class],
        VideoSessionEnded::class => [LogVideoSession::class],
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
        $this->registerPolicies();
    }
}
