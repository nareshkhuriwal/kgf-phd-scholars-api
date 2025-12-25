<?php
// app/Http/Controllers/CitationController.php
namespace App\Http\Controllers;

use App\Models\Citation;
use Illuminate\Http\Request;

class CitationController extends Controller
{
    public function index(Request $req)
    {
        return Citation::with('type')
            ->when($req->q, fn ($q) =>
                $q->where('title', 'like', "%{$req->q}%")
                  ->orWhere('authors', 'like', "%{$req->q}%")
                  ->orWhere('doi', 'like', "%{$req->q}%")
            )
            ->orderBy('year', 'desc')
            ->limit(50)
            ->get();
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'citation_key' => 'required|unique:citations',
            'citation_type_code' => 'required|exists:citation_types,code',
            'title' => 'required',
            'authors' => 'nullable',
            'year' => 'nullable',
            'journal' => 'nullable',
            'publisher' => 'nullable',
            'doi' => 'nullable'
        ]);

        return Citation::create($data);
    }
}
