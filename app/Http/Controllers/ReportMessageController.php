<?php

/**
 * Copyright (c) 2022, Miika Sikala, Essi Passoja, Lauri Klemettilä
 *
 * SPDX-License-Identifier: BSD-2-Clause
 */

namespace App\Http\Controllers;

use App\Http\Resources\ReportMessageResource;
use Illuminate\Http\Request;
use App\Models\ReportMessage;
use App\Models\Report;
use Auth;

class ReportMessageController extends Controller
{
    /**
     * Store a new message to the specified report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $reportId)
    {
        $report = Report::findOrFail($reportId);
        $this->authorize('create-report-message', $report);

        $request->validate([
            'content'       => 'nullable|string|between:0,4095',
            'is_anonymous'  => 'required|boolean',
            'type'          => 'nullable|string|in:text,audio',
            'lat'           => 'nullable|numeric',
            'lon'           => 'nullable|numeric',
            'file'          => 'required_if:type,audio|file|mimes:audio/mpeg,mpga,mp3,wav,aac|max:10240',
        ]);

        $data = $request->all();

        // Handle file upload
        if ($request->hasFile('file') && ($request->type === 'audio')) {
            $path = $request->file('file')->store('audio', 'public');
            $data['file_path'] = '/storage/' . $path;
        }

        // Ensure content is not null for audio messages
        if (($data['type'] ?? 'text') === 'audio' && empty($data['content'])) {
            $data['content'] = '[Audio Message]';
        }

        $message = new ReportMessage($data);
        $message->author_id = Auth::user()->id;
        // Default type to text if not provided
        $message->type = $data['type'] ?? 'text';

        $report->messages()->save($message);

        \App\Events\MessageCreated::dispatch($message);

        return response(['id' => $message->id], 201);
    }

    public function show($id)
    {
        $message = ReportMessage::with('author')->findOrFail($id);
        return response()->json($message);
    }



    /**
     * Update the specified report message.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $reportMessage = ReportMessage::findOrFail($id);
        $this->authorize('update-report-message', $reportMessage);

        $request->validate([
            'content'       => 'string|between:0,4095',
            'is_anonymous'  => 'required|boolean',
        ]);

        $reportMessage->update( $request->all() );
    }

    /**
     * Remove the specified report message.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $reportMessage = ReportMessage::findOrFail($id);
        $this->authorize('delete-report-message', $reportMessage);

        $reportMessage->delete();
    }
}
