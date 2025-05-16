<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

class TerminalTokenController extends Controller
{
    public function index()
    {
        // Get terminal tokens sample data
        $terminalTokens = $this->getSampleTokenData();
        
        return view('dashboard.terminal-tokens', compact('terminalTokens'));
    }
    
    public function regenerate($terminalId)
    {
        try {
            // In a real implementation, you'd verify the terminal exists in pos_terminals table
            
            // Generate a JWT token as described in the documentation
            $token = $this->generateJwtToken($terminalId);
            $expiresAt = now()->addMonth()->format('Y-m-d H:i:s'); // Tokens typically expire in 30 days
            
            $tokenData = [
                'terminal_id' => $terminalId,
                'access_token' => $token,
                'expires_at' => $expiresAt,
                'token_type' => 'Bearer' // As specified in the documentation
            ];
            
            // Log the regeneration attempt
            Log::info('JWT Token regenerated for POS terminal', ['terminal_id' => $terminalId]);
            
            if (request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Terminal token regenerated successfully',
                    'data' => $tokenData
                ]);
            }
            
            return redirect()->route('terminal-tokens')
                ->with('success', 'Terminal token regenerated successfully')
                ->with('token_info', $tokenData);
                
        } catch (\Exception $e) {
            Log::error('Error regenerating terminal token', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage()
            ]);
            
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error regenerating token: ' . $e->getMessage()
                ], 500);
            }
            
            return redirect()->route('terminal-tokens')
                ->with('error', 'Error regenerating token: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate a JWT token for terminal authentication
     * Following the implementation details from the documentation
     */
    private function generateJwtToken($terminalId)
    {
        // In a real implementation, you would use a secret key from configuration
        $secretKey = config('auth.jwt_secret', 'your-secret-key-for-jwt-tokens');
        
        $payload = [
            'iss' => config('app.url'), // Issuer
            'aud' => 'pos_terminal', // Audience
            'iat' => time(), // Issued at
            'exp' => time() + (30 * 24 * 60 * 60), // Expires in 30 days
            'sub' => $terminalId, // Subject (terminal ID)
            'jti' => Str::random(16), // JWT ID (unique identifier for the token)
        ];
        
        // In a real implementation, you would use the Firebase JWT package
        // For the demonstration, we'll simulate a JWT token
        // You would need: composer require firebase/php-jwt
        
        // Simulating JWT creation for demonstration
        // In production use: $token = JWT::encode($payload, $secretKey, 'HS256');
        $token = base64_encode(json_encode($payload)) . '.' . 
                 base64_encode('header-data') . '.' . 
                 base64_encode('signature');
        
        return $token;
    }
    
    private function getSampleTokenData()
    {
        // Updated sample data to match the JWT token use cases
        return collect([
            (object)[
                'terminal_id' => 1,
                'is_revoked' => false,
                'token_type' => 'Bearer',
                'created_at' => '2025-05-16 04:01:47',
                'expires_at' => '2025-06-15 04:01:47',
                'status' => 'active'
            ],
            (object)[
                'terminal_id' => 2,
                'is_revoked' => false,
                'token_type' => 'Bearer',
                'created_at' => '2025-05-16 04:24:23',
                'expires_at' => '2025-06-15 04:24:23',
                'status' => 'active'
            ],
        ]);
    }
}