<?php

namespace App\Filament\Resources\VehicleCategories;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\VehicleCategory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VehicleCategoryResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = VehicleCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Vehicle classes & pricing';

    protected static ?int $navigationSort = 3;

    protected static string $viewPermission = 'pricing.manage';

    protected static ?string $managePermission = 'pricing.manage';

    public static function form(Schema $schema): Schema
    {
        $money = fn (string $f, string $label) => TextInput::make($f)->label($label)->numeric()->minValue(0)->prefix('PKR')->required();

        return $schema->columns(1)->components([
            Section::make('Vehicle class')->columns(3)->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('icon')->options(['bike' => 'Bike', 'rickshaw' => 'Rickshaw', 'car' => 'Car', 'car_premium' => 'Premium car'])->required(),
                TextInput::make('description')->columnSpan(2),
                TextInput::make('capacity')->numeric()->minValue(1)->maxValue(12)->required(),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
            ]),
            Section::make('Fare card')->columns(4)->schema([
                $money('base_fare', 'Base fare'), $money('per_km', 'Per km'), $money('per_minute', 'Per minute'), $money('minimum_fare', 'Minimum fare'),
                $money('booking_fee', 'Booking fee'), $money('waiting_per_minute', 'Waiting / min'), $money('cancellation_fee', 'Cancellation fee'),
            ]),
            Section::make('Negotiation bounds & commission')->description('Drivers and customers can only negotiate between min×suggested and max×suggested.')->columns(3)->schema([
                TextInput::make('min_offer_ratio')->numeric()->minValue(0.3)->maxValue(1)->step(0.05)->required(),
                TextInput::make('max_offer_ratio')->numeric()->minValue(1)->maxValue(5)->step(0.05)->required(),
                TextInput::make('commission_percent')->numeric()->minValue(0)->maxValue(50)->suffix('%')->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('name')->weight('bold')->description(fn ($record) => $record->description),
            TextColumn::make('base_fare')->money('PKR'),
            TextColumn::make('per_km')->money('PKR'),
            TextColumn::make('per_minute')->money('PKR'),
            TextColumn::make('minimum_fare')->money('PKR'),
            TextColumn::make('commission_percent')->suffix('%')->label('Commission'),
            TextColumn::make('capacity'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()->after(fn ($record) => AuditLog::record('pricing.category_updated', $record, [], $record->getChanges()))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleCategories::route('/'), 'create' => Pages\CreateVehicleCategory::route('/create'), 'edit' => Pages\EditVehicleCategory::route('/{record}/edit')];
    }
}
