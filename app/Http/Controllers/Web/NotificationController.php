<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function bell(Request $request)
    {
        $items = Notification::where('channel', 'web')->where('user_id', $request->user()->id)->latest('id')->limit(15)->get()
            ->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title ?? (Notification::TYPES[$n->type] ?? ''), 'body' => $n->body, 'url' => $n->url,
                'read' => $n->read_at !== null, 'time' => $n->created_at->diffForHumans(),
            ]);

        return response()->json(['items' => $items]);
    }

    public function readAll(Request $request)
    {
        Notification::where('channel', 'web')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
