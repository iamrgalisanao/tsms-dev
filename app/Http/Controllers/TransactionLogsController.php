public function index(Request $request)
{
$query = Transaction::query()
->with(['terminal', 'provider'])
->when($request->filled('transaction_id'), function ($q) use ($request) {
$search = trim($request->transaction_id);
// Remove TX- prefix if present for searching
$search = str_replace('TX-', '', $search);
return $q->where('transaction_id', 'like', "%{$search}%");
})
->when($request->filled('provider_id'), function ($q) use ($request) {
return $q->where('provider_id', $request->provider_id);
})
->when($request->filled('terminal_id'), function ($q) use ($request) {
return $q->where('terminal_id', $request->terminal_id);
})
->when($request->filled('date'), function ($q) use ($request) {
return $q->whereDate('created_at', $request->date);
});

$logs = $query->latest()->paginate(15);

return view('transactions.logs.index', [
'logs' => $logs,
'providers' => Provider::all(),
'terminals' => Terminal::all(),
]);
}