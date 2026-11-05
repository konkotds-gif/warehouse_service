<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Код из SMS по договору — подписание (purpose = sign) или закрытие (close); хранится только HMAC кода. */
class ContractSmsCode extends Model
{
    protected $fillable = ['contract_id', 'purpose', 'phone', 'code_hash', 'expires_at', 'attempts', 'provider_id', 'sms_message_id', 'error', 'sent_by', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(StorageContract::class, 'contract_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function smsMessage(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class);
    }

    public function isDelivered(): bool
    {
        return $this->error === null;
    }
}
