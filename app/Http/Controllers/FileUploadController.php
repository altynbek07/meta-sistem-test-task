<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    public function initUpload(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'filesize' => 'required|integer',
            'filetype' => 'required|string',
        ]);

        $uploadId = (string) Str::uuid();
        
        Storage::makeDirectory("chunks/{$uploadId}");
        
        return response()->json([
            'upload_id' => $uploadId,
            'status' => 'initialized',
        ]);
    }
    
    public function uploadChunk(Request $request, string $uploadId)
    {
        $request->validate([
            'chunk' => ['required', File::default()],
            'index' => 'required|integer',
            'total_chunks' => 'required|integer',
            'filename' => 'required|string',
        ]);
        
        $chunk = $request->file('chunk');
        $chunkIndex = $request->input('index');
        
        $chunk->storeAs("chunks/{$uploadId}", $chunkIndex);
        
        return response()->json([
            'status' => 'chunk_uploaded',
            'chunk_index' => $chunkIndex,
        ]);
    }
    
    public function finalizeUpload(Request $request, string $uploadId)
    {
        $request->validate([
            'filename' => 'required|string',
            'total_chunks' => 'required|integer',
        ]);
        
        $filename = $request->input('filename');
        $totalChunks = $request->input('total_chunks');
        
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!Storage::exists("chunks/{$uploadId}/{$i}")) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Missing chunk {$i}",
                ], 400);
            }
        }
        
        $safeFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        $finalPath = "uploads/" . $safeFilename;
        
        // Если имя файла уже существует, добавляем уникальный идентификатор
        if (Storage::exists($finalPath)) {
            $safeFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) . '_' . Str::random(8) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
            $finalPath = "uploads/" . $safeFilename;
        }
        
        Storage::disk('public')->put($finalPath, '');
        
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkContent = Storage::get("chunks/{$uploadId}/{$i}");
            Storage::disk('public')->append($finalPath, $chunkContent);
        }
        
        Storage::deleteDirectory("chunks/{$uploadId}");
        
        return response()->json([
            'status' => 'completed',
            'filename' => $safeFilename,
            'path' => $finalPath,
            'url' => Storage::url($finalPath),
        ]);
    }
    
    public function getUploadStatus(Request $request, string $uploadId)
    {
        $chunks = Storage::files("chunks/{$uploadId}");
        $chunkCount = count($chunks);
        
        return response()->json([
            'status' => 'in_progress',
            'chunks_received' => $chunkCount,
            'upload_id' => $uploadId,
        ]);
    }
} 