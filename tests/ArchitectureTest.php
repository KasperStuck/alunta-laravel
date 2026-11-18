<?php

arch('the Alunta Laravel package uses no Filament or application code')
    ->expect('KasperStuck\Alunta\Laravel')
    ->not->toUse(['Filament', 'Livewire', 'App', 'KasperStuck\Alunta\Filament']);
