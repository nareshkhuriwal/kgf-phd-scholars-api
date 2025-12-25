<?php
// app/Http/Controllers/CitationTypeController.php
namespace App\Http\Controllers;

use App\Models\CitationType;

class CitationTypeController extends Controller
{
    public function index()
    {
        return CitationType::where('is_active', 1)
            ->orderBy('label')
            ->get();
    }
}
