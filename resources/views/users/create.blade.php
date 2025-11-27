@extends('layouts.master')
@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-primary">
            <h3 class="card-title text-white mb-0">Add User</h3>
        </div>
        <div class="card-body">
            <form action="{{ route('users.store') }}" method="POST" autocomplete="off">
                @csrf
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" name="name" id="name" class="form-control" required value="{{ old('name') }}">
                    @error('name')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control" required value="{{ old('email') }}">
                    @error('email')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control mr-2" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">Show</button>
                    </div>
                    <small class="form-text text-muted">Minimum 8 characters, include letters and numbers.</small>
                    @error('password')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="role" class="form-label">Role</label>
                    <select name="role" id="role" class="form-control">
                        <option value="">-- Select role --</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->name }}" {{ (old('role') == $role->name) ? 'selected' : '' }}>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">Assign a single role to the user.</small>
                    @error('role')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-success mr-2">Create</button>
                    <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <script>
                function togglePassword() {
                    var pwd = document.getElementById('password');
                    if (pwd.type === 'password') {
                        pwd.type = 'text';
                    } else {
                        pwd.type = 'password';
                    }
                }
            </script>
        </div>
    </div>
</div>
</div>
@endsection
