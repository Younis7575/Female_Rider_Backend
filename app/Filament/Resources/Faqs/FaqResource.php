<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\Faq;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

class FaqResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'FAQs';

    protected static string $viewPermission = 'cms.manage';

    protected static ?string $managePermission = 'cms.manage';

    protected static bool $allowDelete = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('category')->required()->default('general')->datalist(['general', 'safety', 'payments', 'drivers', 'account']),
                Select::make('audience')->options(['all' => 'Everyone', 'customers' => 'Customers', 'drivers' => 'Drivers'])->required()->default('all'),
                TextInput::make('sort_order')->numeric()->default(0),
                TextInput::make('question')->required()->columnSpanFull(),
                Textarea::make('answer')->required()->rows(5)->columnSpanFull(),
                Toggle::make('is_published')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('sort_order')->defaultSort('sort_order')->columns([
            TextColumn::make('question')->searchable()->wrap()->weight('bold'),
            TextColumn::make('category')->badge(),
            TextColumn::make('audience')->badge()->color('gray'),
            IconColumn::make('is_published')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFaqs::route('/'), 'create' => Pages\CreateFaq::route('/create'), 'edit' => Pages\EditFaq::route('/{record}/edit')];
    }
}
