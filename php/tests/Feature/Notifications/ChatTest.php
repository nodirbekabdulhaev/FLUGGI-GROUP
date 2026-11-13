<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use CommsFixtures;

    public function test_direct_chat_unread_and_telegram_once_until_read(): void
    {
        $this->fixtures();
        config(['fluggi.telegram.token' => '1:x']);
        $this->rop->update(['telegram_chat_id' => '555']);
        $contacts = $this->actingAsUser($this->manager)->getJson('/chats/contacts')->assertOk()->json();
        $this->assertNotContains($this->manager->id, array_column($contacts, 'id'));
        $this->assertContains($this->rop->id, array_column($contacts, 'id'));
        $this->postJson('/chats/direct', ['userId' => $this->manager->id])->assertStatus(422);

        $c = $this->postJson('/chats/direct', ['userId' => $this->rop->id])->assertOk()->json('id');
        $this->assertSame($c, $this->actingAsUser($this->rop)->postJson('/chats/direct', ['userId' => $this->manager->id])->json('id'));

        $this->actingAsUser($this->manager)->postJson("/chats/$c/messages", ['body' => 'Привет!'])->assertCreated();
        $this->postJson("/chats/$c/messages", ['body' => 'Как дела по Alfa?'])->assertCreated();
        $this->actingAsUser($this->rop)->getJson('/chats/unread')->assertExactJson(['count' => 2]);
        $this->actingAsUser($this->manager)->getJson('/chats/unread')->assertExactJson(['count' => 0]);
        // Telegram РОП — одно уведомление на два сообщения, в колокольчик не дублируется
        $this->assertSame(1, NotificationDelivery::where('user_id', $this->rop->id)->where('type', 'chat.message')->count());
        $this->assertSame(0, Notification::where('type', 'chat.message')->count());

        $list = $this->actingAsUser($this->rop)->getJson('/chats')->json();
        $this->assertSame($this->manager->id, $list[0]['peer']['id']);
        $this->assertSame(2, $list[0]['unread']);
        $this->assertSame(['Как дела по Alfa?', false], [$list[0]['lastMessage']['body'], $list[0]['lastMessage']['mine']]);
        $this->assertSame(['Привет!', 'Как дела по Alfa?'], array_column($this->getJson("/chats/$c/messages")->json(), 'body'));
        $this->postJson("/chats/$c/read")->assertNoContent();
        $this->getJson('/chats/unread')->assertExactJson(['count' => 0]);
        $this->travel(1)->seconds();
        $this->actingAsUser($this->manager)->postJson("/chats/$c/messages", ['body' => 'Ещё вопрос'])->assertCreated();
        $this->assertSame(2, NotificationDelivery::where('user_id', $this->rop->id)->where('type', 'chat.message')->count());

        // Посторонний не читает чужую переписку
        $this->actingAsUser($this->executor)->getJson("/chats/$c/messages")->assertNotFound();
        $this->postJson("/chats/$c/messages", ['body' => 'x'])->assertNotFound();
        $this->getJson('/chats')->assertExactJson([]);
        // Виджет чата и дел рендерится на любой странице
        $this->get('/notifications')->assertOk()->assertSee(t('chat.title'))->assertSee(t('todos.dockTitle'));
    }
}
