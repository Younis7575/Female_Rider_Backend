<?php

namespace App\Filament\Resources\DriverDeposits;

use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\DriverDeposit;
use App\Services\Finance\LedgerService;
use App\Services\Finance\ManualPaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Manual JazzCash payments from drivers: check the screenshot/TID, then approve to credit her account. */
class DriverDepositResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = DriverDeposit::class;

    protected static ?string $modelLabel = 'Driver payment';

    protected static ?string $navigationLabel = 'Driver payments (JazzCash)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'finance.payouts.approve';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = DriverDeposit::query()->where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $wrap = function (callable $fn, string $msg) {
            try {
                $fn();
                Notification::make()->title($msg)->success()->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            }
        };

        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('driver', 'reviewer'))->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i')->label('Submitted'),
            TextColumn::make('driver.name')->label('Driver')->searchable()
                ->description(fn ($record) => ($record->driver?->phone ?? '').' · Balance: PKR '.number_format(app(LedgerService::class)->driverBalance($record->driver_id), 2)),
            TextColumn::make('amount')->money('PKR')->label('Sent (claimed)')
                ->description(fn ($record) => $record->approved_amount !== null && $record->approved_amount != $record->amount ? 'Approved: PKR '.number_format($record->approved_amount, 2) : null),
            TextColumn::make('transaction_ref')->label('TID')->searchable()->copyable()
                ->description(fn ($record) => $record->sender_number ? 'From '.$record->sender_number : null),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', default => 'warning',
            }),
            TextColumn::make('reviewer.name')->label('Verified by')->placeholder('—')
                ->description(fn ($record) => $record->rejection_reason),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('pending')])
            ->recordActions([
                Action::make('screenshot')->icon(Heroicon::OutlinedPhoto)->color('gray')
                    ->url(fn (DriverDeposit $record) => route('admin.secure.file', ['type' => 'payment-proof', 'id' => $record->id]), true),
                Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn ($record) => $record->status === 'pending' && static::canManage())
                    ->modalDescription('Approve only after you see this TID and amount in the FemaleRider JazzCash account. The amount is credited to the driver\'s balance.')
                    ->fillForm(fn (DriverDeposit $record) => ['amount' => $record->amount])
                    ->schema([TextInput::make('amount')->label('Amount received (PKR)')->numeric()->minValue(1)->required()])
                    ->action(fn ($record, array $data) => $wrap(fn () => app(ManualPaymentService::class)->approve(auth('admin')->user(), $record, (float) $data['amount']), 'Payment approved and credited')),
                Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending' && static::canManage())
                    ->schema([Textarea::make('reason')->required()->maxLength(255)->label('Reason (shown to driver)')])
                    ->action(fn ($record, array $data) => $wrap(fn () => app(ManualPaymentService::class)->reject(auth('admin')->user(), $record, $data['reason']), 'Payment rejected')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDriverDeposits::route('/')];
    }
}
