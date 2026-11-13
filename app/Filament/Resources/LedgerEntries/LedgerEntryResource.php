<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\LedgerEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Immutable double-entry ledger (read-only). */
class LedgerEntryResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = LedgerEntry::class;

    protected static ?string $navigationLabel = 'Ledger';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = null;

    public static function table(Table $table): Table
    {
        $accounts = ['customer_receivable', 'driver_payable', 'platform_revenue', 'promotions_expense', 'gateway_clearing', 'refunds_expense', 'customer_refunds_payable', 'payout_clearing'];

        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('user'))->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i:s'),
            TextColumn::make('transaction_id')->label('Txn')->limit(8)->fontFamily('mono')->searchable(),
            TextColumn::make('account')->badge()->color('gray'),
            TextColumn::make('user.name')->label('Party')->placeholder('Platform'),
            TextColumn::make('entry_type')->badge(),
            TextColumn::make('debit')->state(fn ($record) => $record->direction === 'debit' ? $record->amount : null)->money('PKR')->placeholder(''),
            TextColumn::make('credit')->state(fn ($record) => $record->direction === 'credit' ? $record->amount : null)->money('PKR')->placeholder(''),
            TextColumn::make('description')->limit(50)->wrap(),
            TextColumn::make('actor_type')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('account')->options(array_combine($accounts, array_map(fn ($a) => str_replace('_', ' ', $a), $accounts))),
            SelectFilter::make('entry_type')->options(['trip_fare' => 'Trip fare', 'commission' => 'Commission', 'cash_collection' => 'Cash collection', 'payment_capture' => 'Payment capture', 'promotion' => 'Promotion', 'refund' => 'Refund', 'payout' => 'Payout', 'adjustment' => 'Adjustment', 'cancellation_fee' => 'Cancellation fee']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLedgerEntries::route('/')];
    }
}
