<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrDocumentSignature extends Model
{
    protected $fillable = [
        'pr_document_id', 'document_role_id', 'step_no', 'role', 'duty',
        'signer_id_thai_hash', 'signer_name', 'signature_data', 'signed_at',
    ];

    protected $casts = [
        'step_no' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PrDocument::class, 'pr_document_id');
    }

    public function documentRole(): BelongsTo
    {
        return $this->belongsTo(DocumentRole::class, 'document_role_id');
    }
}
