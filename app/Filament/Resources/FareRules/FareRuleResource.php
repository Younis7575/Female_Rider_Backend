<?php

namespace App\Filament\Resources\FareRules;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\FareRule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class FareRuleResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = FareRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Fare rules';

    protected static ?int $navigationSort = 4;

    protected static string $viewPermission = 'pricing.manage';

    protected static ?string $managePermission = 'pricing.manage';

    protected static bool $allowDelete = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Scope')->columns(3)->schema([
                TextInput::make('name')->required(),
                Select::make('vehicle_category_id')->relationship('vehicleCategory', 'name')->required(),
                Select::make('service_zone_id')->relationship('serviceZone', 'name')->placeholder('All zones'),
            ]),
            Section::make('Overrides (leave blank to inherit from vehicle class)')->columns(3)->schema([
                TextInput::make('base_fare')->numeric()->prefix('PKR'),
                TextInput::make('per_km')->numeric()->prefix('PKR'),
                TextInput::make('per_minute')->numeric()->prefix('PKR'),
                TextInput::make('minimum_fare')->numeric()->prefix('PKR'),
                TextInput::make('commission_percent')->numeric()->suffix('%'),
                TextInput::make('time_multiplier')->numeric()->minValue(1)->maxValue(2)->step(0.05)->default(1),
            ]),
            Section::make('When it applies')->columns(3)->schema([
                TimePicker::make('applies_from')->seconds(false),
                TimePicker::make('applies_to')->seconds(false),
                CheckboxList::make('days_of_week')->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])->columns(7)->columnSpanFull(),
                DateTimePicker::make('effective_from'),
                DateTimePicker::make('effective_to'),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->weight('bold'),
            TextColumn::make('vehicleCategory.name'),
            TextColumn::make('serviceZone.name')->placeholder('All zones'),
            TextColumn::make('time_multiplier')->suffix('×'),
            TextColumn::make('applies_from')->time('H:i'),
            TextColumn::make('applies_to')->time('H:i'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFareRules::route('/'), 'create' => Pages\CreateFareRule::route('/create'), 'edit' => Pages\EditFareRule::route('/{record}/edit')];
    }
}
