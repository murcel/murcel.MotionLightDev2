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

        // ---- Runtime variables ----
        $this->RegisterVariableBoolean('Enabled', 'Bewegungserkennung aktiv', '~Switch', 1);
        $this->EnableAction('Enabled');

        $this->RegisterVariableInteger('CountdownSec', 'Auto-Off Restzeit (s)', '~UnixTimestampTime', 2);
        // ~UnixTimestampTime zeigt hh:mm:ss; wir schreiben hier Sekunden rein – rein optisch ausreichend.
        // Alternativ könnte ein eigenes Profil angelegt werden.

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

        // Mirror StartEnabled → Enabled (only when freshly created or variable not yet set)
        $enabledVarID = $this->GetIDForIdent('Enabled');
        if ($enabledVarID && IPS_VariableExists($enabledVarID)) {
            if (GetValue($enabledVarID) === null) {
                @SetValueBoolean($enabledVarID, (bool)$this->ReadPropertyBoolean('StartEnabled'));
            }
        } else {
            @SetValueBoolean($enabledVarID, (bool)$this->ReadPropertyBoolean('StartEnabled'));
        }

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
        $this->setRegisteredIDs($new);

        // Ensure timers are stopped on config change
        $this->SetTimerInterval('AutoOff', 0);
        $this->SetTimerInterval('CountdownTick', 0);
        $this->WriteAttributeInteger('AutoOffUntil', 0);
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
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

        // Movement?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            $mv = (bool)@GetValueBoolean($SenderID);
            // only react on TRUE edges
            if ($mv) {
                $this->switchLights(true);
                $this->armAutoOffTimer();
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
    }

    private function armAutoOffIfIdle(): void
    {
        $current = (int)$this->ReadAttributeInteger('AutoOffUntil');
        if ($current <= time()) {
            $this->armAutoOffTimer();
        }
        // if still running, do nothing to avoid chattering
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
}