<?php
declare(strict_types=1);

class RoomMotionLightsDev2 extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // TODO: Properties/Variablen/Timer kommen später
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // TODO: Registrierungen etc. folgen später
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [],
            'actions'  => [],
            'status'   => []
        ]);
    }
}