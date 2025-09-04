<?php
declare(strict_types=1);

class RoomMotionLightsDev2 extends IPSModule
{
    private const VM_UPDATE = 10603; // VariableManager: Update

    /* ================= Lifecycle ================= */
    public function Create()
    {
        parent::Create();

        // ---- Properties (from configuration form) ----
        $this->RegisterPropertyString('MotionVars', '[]'); // [{var:int}]
        $this->RegisterPropertyString('Lights', '[]');     // [{switchVar:int}]
        $this->RegisterPropertyInteger('TimeoutSec', 60);
        $this->RegisterPropertyBoolean('StartEnabled', true);

        // Status-Variablen (Raum/Haus; Inhibit/Require)
        $this->RegisterPropertyString('RoomInhibit', '[]');  // [{var:int}]
        $this->RegisterPropertyString('HouseInhibit', '[]'); // [{var:int}]
        $this->RegisterPropertyString('RoomRequire', '[]');  // [{var:int}]
        $this->RegisterPropertyString('HouseRequire', '[]'); // [{var:int}]

        // Lux (optional)
        $this->RegisterPropertyInteger('LuxVar', 0); // VariableID eines Helligkeitssensors
        $this->RegisterPropertyInteger('LuxMax', 50);
        $this->RegisterPropertyBoolean('UseLux', true);

        // ---- Profiles ----
        $this->ensureProfiles();

        // ---- Runtime variables ----
        $this->RegisterVariableBoolean('Enabled', 'Bewegungserkennung aktiv', '~Switch', 1);
        $this->EnableAction('Enabled');
        // Startzustand direkt bei Create setzen
        @SetValueBoolean($this->GetIDForIdent('Enabled'), (bool)$this->ReadPropertyBoolean('StartEnabled'));

        $this->RegisterVariableInteger('CountdownSec', 'Auto-Off Restzeit (s)', 'RMLDEV2.Seconds', 2);

        // Status-Indicatoren (read-only)
        $this->RegisterVariableBoolean('RoomInhibitActive', 'Raum-Inhibit aktiv', 'RMLDEV2.Block', 3);
        $this->RegisterVariableBoolean('HouseInhibitActive', 'Haus-Inhibit aktiv', 'RMLDEV2.Block', 4);
        $this->RegisterVariableBoolean('RequireSatisfied', 'Erfordern erfüllt/OK', 'RMLDEV2.Passed', 5);
        $this->RegisterVariableBoolean('LuxOK', 'Lux-Bedingung OK', 'RMLDEV2.Passed', 6);

        // ---- Timers ----
        $this->RegisterTimer('AutoOff', 0, 'RMLDEV2_AutoOff($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CountdownTick', 0, 'RMLDEV2_CountdownTick($_IPS[\'TARGET\']);');

        // ---- Attributes ----
        $this->RegisterAttributeInteger('AutoOffUntil', 0);
        $this->RegisterAttributeString('RegisteredIDs', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Profiles sicherstellen (z.B. nach Updates)
        $this->ensureProfiles();

        // Unregister previous Message subscriptions
        $prev = $this->getRegisteredIDs();
        foreach ($prev as $id) {
            if (@IPS_ObjectExists($id)) {
                @$this->UnregisterMessage($id, self::VM_UPDATE);
            }
        }
        $new = [];

        // Register to Motion variables
        foreach ($this->getMotionVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Register to Light switch variables (to observe manual on/off and reset countdown)
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            if ($sv > 0 && @IPS_VariableExists($sv)) {
                $this->RegisterMessage($sv, self::VM_UPDATE);
                $new[] = $sv;
            }
        }
        // Register to status variables (room/house inhibit/require)
        foreach (['RoomInhibit','HouseInhibit','RoomRequire','HouseRequire'] as $prop) {
            foreach ($this->getBoolVarList($prop) as $vid) {
                $this->RegisterMessage($vid, self::VM_UPDATE);
                $new[] = $vid;
            }
        }

        // Register to Lux variable if used
        $luxVid = $this->getLuxVar();
        if ($luxVid > 0 && $this->isLuxConfigured()) {
            $this->RegisterMessage($luxVid, self::VM_UPDATE);
            $new[] = $luxVid;
        }

        // Validate no variable appears in multiple status lists
        $allLists = [
            'RoomInhibit'  => $this->getBoolVarList('RoomInhibit'),
            'HouseInhibit' => $this->getBoolVarList('HouseInhibit'),
            'RoomRequire'  => $this->getBoolVarList('RoomRequire'),
            'HouseRequire' => $this->getBoolVarList('HouseRequire')
        ];
        $seen = [];
        foreach ($allLists as $listName => $ids) {
            foreach ($ids as $id) {
                if (isset($seen[$id]) && $seen[$id] !== $listName) {
                    $this->SendDebug('Config', sprintf('Variable %d ist mehrfach konfiguriert (%s & %s). Bitte korrigieren.', $id, $seen[$id], $listName), 0);
                } else {
                    $seen[$id] = $listName;
                }
            }
        }

        $this->setRegisteredIDs($new);

        // Ensure timers are stopped on config change
        $this->SetTimerInterval('AutoOff', 0);
        $this->SetTimerInterval('CountdownTick', 0);
        $this->WriteAttributeInteger('AutoOffUntil', 0);
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);

        $this->updateStatusIndicators();
    }

    /* ================= Configuration Form ================= */
    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [[
                    'type' => 'List', 'name' => 'MotionVars', 'caption' => 'Melder',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '350px',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [[
                    'type' => 'List', 'name' => 'Lights', 'caption' => 'Akteure',
                    'columns' => [[
                        'caption' => 'Ein/Aus-Variable', 'name' => 'switchVar', 'width' => '350px',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'UseLux', 'caption' => 'Lux berücksichtigen'],
                    ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable', 'enabled' => (bool)$this->ReadPropertyBoolean('UseLux')],
                    ['type' => 'NumberSpinner',  'name' => 'LuxMax', 'caption' => 'Wenn Lux niedriger als ⇒ schalten', 'minimum' => 0, 'maximum' => 100000, 'enabled' => (bool)$this->ReadPropertyBoolean('UseLux')]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Statusbedingungen (Raum & Haus)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Nicht auslösen, wenn TRUE'],
                    ['type' => 'List', 'name' => 'RoomInhibit', 'caption' => 'Raum – Inhibit',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '350px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ],
                    ['type' => 'List', 'name' => 'HouseInhibit', 'caption' => 'Haus – Inhibit',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '350px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ],
                    ['type' => 'Label', 'caption' => 'Nur auslösen, wenn TRUE ("erzwingen")'],
                    ['type' => 'List', 'name' => 'RoomRequire', 'caption' => 'Raum – Erfordern',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '350px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ],
                    ['type' => 'List', 'name' => 'HouseRequire', 'caption' => 'Haus – Erfordern',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '350px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Einstellungen', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'TimeoutSec', 'caption' => 'Timeout (Sekunden)', 'minimum' => 5, 'maximum' => 3600],
                    ['type' => 'CheckBox', 'name' => 'StartEnabled', 'caption' => 'Beim Start aktivieren']
                ]]
            ],
            'actions'  => [],
            'status'   => []
        ]);
    }

    /* ================= Action handling ================= */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Enabled':
                SetValueBoolean($this->GetIDForIdent('Enabled'), (bool)$Value);
                if (!(bool)$Value) {
                    // stop everything when disabled
                    $this->SetTimerInterval('AutoOff', 0);
                    $this->SetTimerInterval('CountdownTick', 0);
                    $this->WriteAttributeInteger('AutoOffUntil', 0);
                    @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
                }
                $this->updateStatusIndicators();
                break;
        }
    }

    /* ================= Message sink ================= */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) {
            return;
        }
        // ignore when disabled
        if (!$this->isEnabled()) {
            return;
        }
        $this->updateStatusIndicators();

        // Movement?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            $mv = (bool)@GetValueBoolean($SenderID);
            // only react on TRUE edges
            if ($mv) {
                if (!$this->shouldAllowAutoOn()) {
                    return; // Bedingungen nicht erfüllt
                }
                $this->switchLights(true);
                $this->armAutoOffTimer();
                $this->updateStatusIndicators();
            }
            return;
        }

        // Manual changes on light status: if turned on manually and module enabled, (re-)arm timer;
        // if turned off manually and no motion active, stop timers.
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            if ($sv > 0 && $SenderID === $sv) {
                $on = (bool)@GetValueBoolean($sv);
                if ($on) {
                    $this->armAutoOffIfIdle();
                } else {
                    if (!$this->isAnyMotionActive()) {
                        $this->SetTimerInterval('AutoOff', 0);
                        $this->SetTimerInterval('CountdownTick', 0);
                        $this->WriteAttributeInteger('AutoOffUntil', 0);
                        @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
                    }
                }
                return;
            }
        }
    }

    /* ================= Timers ================= */
    public function AutoOff(): void
    {
        // Only switch off when no motion active anymore
        if (!$this->isAnyMotionActive()) {
            $this->switchLights(false);
            $this->SetTimerInterval('AutoOff', 0);
            $this->SetTimerInterval('CountdownTick', 0);
            $this->WriteAttributeInteger('AutoOffUntil', 0);
            @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
        } else {
            // Still motion → re-arm full timeout
            $this->armAutoOffTimer();
        }
        $this->updateStatusIndicators();
    }

    public function CountdownTick(): void
    {
        $until = (int)$this->ReadAttributeInteger('AutoOffUntil');
        if ($until <= 0) {
            $this->SetTimerInterval('CountdownTick', 0);
            @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
            return;
        }
        $remain = max(0, $until - time());
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), $remain);
        if ($remain === 0) {
            $this->SetTimerInterval('CountdownTick', 0);
        }
    }

    private function armAutoOffTimer(): void
    {
        $timeout = $this->getTimeoutSec();
        $until = time() + $timeout;
        $this->WriteAttributeInteger('AutoOffUntil', $until);
        $this->SetTimerInterval('AutoOff', $timeout * 1000);
        $this->SetTimerInterval('CountdownTick', 1000);
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), $timeout);
        $this->updateStatusIndicators();
    }

    private function armAutoOffIfIdle(): void
    {
        $current = (int)$this->ReadAttributeInteger('AutoOffUntil');
        if ($current <= time()) {
            $this->armAutoOffTimer();
        }
        // if still running, do nothing to avoid chattering
        $this->updateStatusIndicators();
    }
    /* ================= Status Indicator Update ================= */
    private function updateStatusIndicators(): void
    {
        // Inhibit
        $roomInhibit  = $this->anyTrue($this->getBoolVarList('RoomInhibit'));
        $houseInhibit = $this->anyTrue($this->getBoolVarList('HouseInhibit'));

        // Require (erzwingen)
        $roomReq  = $this->getBoolVarList('RoomRequire');
        $houseReq = $this->getBoolVarList('HouseRequire');
        $requireNeeded = (count($roomReq) + count($houseReq)) > 0;
        $requireOK = !$requireNeeded || ($this->anyTrue($roomReq) || $this->anyTrue($houseReq));

        // Lux
        $luxOK = !$this->isLuxConfigured() || $this->isLuxOk();

        @SetValueBoolean($this->GetIDForIdent('RoomInhibitActive'), $roomInhibit);
        @SetValueBoolean($this->GetIDForIdent('HouseInhibitActive'), $houseInhibit);
        @SetValueBoolean($this->GetIDForIdent('RequireSatisfied'), $requireOK);
        @SetValueBoolean($this->GetIDForIdent('LuxOK'), $luxOK);
    }

    /* ================= Helpers ================= */
    private function switchLights(bool $on): void
    {
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            if ($sv > 0 && @IPS_VariableExists($sv)) {
                @RequestAction($sv, $on);
            }
        }
    }

    private function getTimeoutSec(): int
    {
        $t = (int)$this->ReadPropertyInteger('TimeoutSec');
        return max(5, min(3600, $t));
    }

    private function isEnabled(): bool
    {
        $id = @$this->GetIDForIdent('Enabled');
        if ($id && IPS_VariableExists($id)) {
            return (bool)@GetValueBoolean($id);
        }
        return (bool)$this->ReadPropertyBoolean('StartEnabled');
    }

    private function isAnyMotionActive(): bool
    {
        foreach ($this->getMotionVars() as $vid) {
            if ((bool)@GetValueBoolean($vid)) {
                return true;
            }
        }
        return false;
    }

    private function getMotionVars(): array
    {
        $raw = @json_decode($this->ReadPropertyString('MotionVars'), true);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $ids[] = (int)($row['var'] ?? 0);
            }
        }
        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0 && @IPS_VariableExists($id))));
    }

    private function getLights(): array
    {
        $arr = @json_decode($this->ReadPropertyString('Lights'), true);
        return is_array($arr) ? $arr : [];
    }

    private function getRegisteredIDs(): array
    {
        $raw = $this->ReadAttributeString('RegisteredIDs');
        $arr = @json_decode($raw, true);
        return is_array($arr) ? array_map('intval', $arr) : [];
    }

    private function setRegisteredIDs(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $this->WriteAttributeString('RegisteredIDs', json_encode($ids));
    }

    /* ================= Status gating (Inhibit/Require) ================= */
    private function getBoolVarList(string $propName): array
    {
        $raw = @json_decode($this->ReadPropertyString($propName), true);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $ids[] = (int)($row['var'] ?? 0);
            }
        }
        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0 && @IPS_VariableExists($id))));
    }

    private function anyTrue(array $ids): bool
    {
        foreach ($ids as $id) {
            $v = @GetValueBoolean($id);
            if ($v === true) {
                return true;
            }
        }
        return false;
    }

    private function shouldAllowAutoOn(): bool
    {
        // 1) Inhibit zuerst: wenn irgendein Inhibit TRUE ist → blockieren
        if ($this->anyTrue($this->getBoolVarList('RoomInhibit')) || $this->anyTrue($this->getBoolVarList('HouseInhibit'))) {
            return false;
        }

        // 2) Require (erzwingen): Wenn Listen konfiguriert sind, muss mind. eine TRUE sein
        $roomReq  = $this->getBoolVarList('RoomRequire');
        $houseReq = $this->getBoolVarList('HouseRequire');
        if ((count($roomReq) + count($houseReq)) > 0) {
            if (!($this->anyTrue($roomReq) || $this->anyTrue($houseReq))) {
                return false; // nichts erzwingt → nicht schalten
            }
        }

        // 3) Lux-Prüfung (wenn konfiguriert/aktiv)
        if ($this->isLuxConfigured() && !$this->isLuxOk()) {
            return false; // zu hell
        }

        return true;
    }

    /* ================= Lux helpers ================= */
    private function isLuxConfigured(): bool
    {
        $use = (bool)$this->ReadPropertyBoolean('UseLux');
        if (!$use) {
            return false;
        }
        $vid = (int)$this->ReadPropertyInteger('LuxVar');
        return $vid > 0 && @IPS_VariableExists($vid);
    }

    private function getLuxVar(): int
    {
        $vid = (int)$this->ReadPropertyInteger('LuxVar');
        return ($vid > 0 && @IPS_VariableExists($vid)) ? $vid : 0;
    }

    private function getLuxMax(): int
    {
        $v = (int)$this->ReadPropertyInteger('LuxMax');
        return max(0, $v);
    }

    private function isLuxOk(): bool
    {
        $vid = $this->getLuxVar();
        if ($vid === 0) {
            return true; // keine Lux-Variable konfiguriert → Lux egal
        }
        $raw = @GetValue($vid);
        if (!is_numeric($raw)) {
            return true; // unlesbar → sicherheitshalber nicht blockieren
        }
        // Vergleich: aktueller Lux ≤ Schwelle?
        return ((float)$raw) <= (float)$this->getLuxMax();
    }

    /* ================= Profiles ================= */
    private function ensureProfiles(): void
    {
        // Boolean profile: Block (TRUE=blockiert, FALSE=frei)
        if (!IPS_VariableProfileExists('RMLDEV2.Block')) {
            IPS_CreateVariableProfile('RMLDEV2.Block', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('RMLDEV2.Block', 0, 'frei', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Block', 1, 'blockiert', '', -1);
        }
        // Boolean profile: Passed/OK (TRUE=OK, FALSE=nicht erfüllt)
        if (!IPS_VariableProfileExists('RMLDEV2.Passed')) {
            IPS_CreateVariableProfile('RMLDEV2.Passed', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('RMLDEV2.Passed', 0, 'nicht erfüllt', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Passed', 1, 'OK', '', -1);
        }
        // Einfaches Sekunden-Profil für Integer: zeigt "XYZ s"
        if (!IPS_VariableProfileExists('RMLDEV2.Seconds')) {
            IPS_CreateVariableProfile('RMLDEV2.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RMLDEV2.Seconds', 0);
            IPS_SetVariableProfileText('RMLDEV2.Seconds', '', ' s');
            IPS_SetVariableProfileValues('RMLDEV2.Seconds', 0, 86400, 1);
        }
    }
}