<?php

namespace Modules\Isite\Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MoveContactSettingsToSettingsTranslationsTableSeeder extends Seeder
{
  public function run()
  {
    $seedUniquesUse = DB::table('isite__seeds')->where('name', 'MoveContactSettingsToSettingsTranslationsTableSeeder')->first();
    if (empty($seedUniquesUse)) {
      $addresses = DB::table('setting__settings')->where('name', 'isite::addresses')->first();
      $emails = DB::table('setting__settings')->where('name', 'isite::emails')->first();
      $phones = DB::table('setting__settings')->where('name', 'isite::phones')->first();
      $availableLocales = json_decode(setting('core::locales'));
      foreach ($availableLocales as $locale) {
        DB::table('setting__setting_translations')->insert(
          [
            'setting_id' => $addresses->id,
            'value' => $addresses->plainValue,
            'locale' => $locale,
          ]
        );
        DB::table('setting__setting_translations')->insert(
          [
            'setting_id' => $emails->id,
            'value' => $emails->plainValue,
            'locale' => $locale,
          ]
        );
        DB::table('setting__setting_translations')->insert(
          [
            'setting_id' => $phones->id,
            'value' => $phones->plainValue,
            'locale' => $locale,
          ]
        );
      }
      DB::table('isite__seeds')->insert(['name' => 'MoveContactSettingsToSettingsTranslationsTableSeeder']);
    }
  }
}