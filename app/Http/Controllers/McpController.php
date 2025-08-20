<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class McpController extends Controller
{
    public function handle(Request $request)
    {
        // You may want to add authentication/authorization here
        return response()->json(['message' => 'MCP endpoint is working']);
    }
}
