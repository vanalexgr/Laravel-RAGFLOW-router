<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        return response()->stream(function () {
            // initial hello event (optional)
            echo "event: ready\n";
            echo 'data: {"ok":true}' . "\n\n";
            @ob_flush();
            @flush();

            // keep-alive loop (very basic)
            while (true) {
                echo "event: ping\n";
                echo 'data: {}' . "\n\n";
                @ob_flush();
                @flush();
                sleep(15);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // Nginx: disable buffering
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function message(Request $request)
    {
        // receive client->server messages
        // (store/dispatch them somewhere your stream can pick up)
        // In a real implementation, you'd pass this to the MCP server instance
        return response()->json(['ok' => true]);
    }
}
