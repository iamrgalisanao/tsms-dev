<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Transaction System</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md w-96">
            <h1 class="text-2xl font-bold mb-6 text-center">Transaction System</h1>
            
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" name="email" id="email" required 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" name="password" id="password" required 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                @if ($errors->any())
                    <div class="bg-red-50 text-red-500 p-4 rounded-md">
                        {{ $errors->first() }}
                    </div>
                @endif

                <button type="submit" 
                        class="w-full bg-blue-600 text-white rounded-md py-2 px-4 hover:bg-blue-700">
                    Log in
                </button>
            </form>
        </div>
    </div>
</body>
</html>
