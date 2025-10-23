<?php

namespace App\Http\Controllers;

use App\Models\TypeDoc;
use Illuminate\Http\Request;

class TypeDocController extends Controller
{
    public function index()
    {
        return TypeDoc::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom' => 'required|string|max:255',
        ]);

        return TypeDoc::create($request->all());
    }

    public function show(TypeDoc $typeDoc)
    {
        return $typeDoc;
    }

    public function update(Request $request, TypeDoc $typeDoc)
    {
        $request->validate([
            'nom' => 'required|string|max:255',
        ]);

        $typeDoc->update($request->all());
        return $typeDoc;
    }

    public function destroy(TypeDoc $typeDoc)
    {
        $typeDoc->delete();
        return response()->json(null, 204);
    }
}