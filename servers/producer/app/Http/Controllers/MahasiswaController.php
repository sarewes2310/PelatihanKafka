<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Requests\MahasiswaRequest;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Junges\Kafka\Facades\Kafka;

class MahasiswaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $mahasiswas = Mahasiswa::paginate();

        return view('mahasiswa.index', compact('mahasiswas'))
            ->with('i', ($request->input('page', 1) - 1) * $mahasiswas->perPage());
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $mahasiswa = new Mahasiswa();

        return view('mahasiswa.create', compact('mahasiswa'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MahasiswaRequest $request): RedirectResponse
    {
        $mahasiswa = Mahasiswa::create($request->validated());

        Kafka::asyncPublish(config('kafka.brokers'))
            ->onTopic('pelatihan_kafka.producer.mahasiswa')
            ->withHeaders([
                'id' => $mahasiswa->id,
            ])
            ->withBody([
                'event' => 'mahasiswa.created',
                'data'  => $mahasiswa->toArray(),
                'sent_at' => now()->toIso8601String(),
            ])
            ->send();

        return Redirect::route('mahasiswas.index')
            ->with('success', 'Mahasiswa created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($id): View
    {
        $mahasiswa = Mahasiswa::find($id);

        return view('mahasiswa.show', compact('mahasiswa'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id): View
    {
        $mahasiswa = Mahasiswa::find($id);

        return view('mahasiswa.edit', compact('mahasiswa'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MahasiswaRequest $request, Mahasiswa $mahasiswa): RedirectResponse
    {
        $mahasiswa->update($request->validated());

        Kafka::asyncPublish(config('kafka.brokers'))
            ->onTopic('pelatihan_kafka.producer.mahasiswa')
            ->withHeaders([
                'id' => $mahasiswa->id,
            ])
            ->withBody([
                'event' => 'mahasiswa.updated',
                'data'  => $mahasiswa->toArray(),
                'sent_at' => now()->toIso8601String(),
            ])
            ->send();

        return Redirect::route('mahasiswas.index')
            ->with('success', 'Mahasiswa updated successfully');
    }

    public function destroy($id): RedirectResponse
    {
        Mahasiswa::find($id)->delete();

        return Redirect::route('mahasiswas.index')
            ->with('success', 'Mahasiswa deleted successfully');
    }
}
