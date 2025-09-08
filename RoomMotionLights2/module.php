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
        $this->RegisterPropertyString('Lights', '[]');     // [{switchVar:int, dimmerVar:int}]
        $this->RegisterPropertyInteger('TimeoutSec', 60);
        $this->RegisterPropertyInteger('DefaultDimPct', 20); // 1..100 % Zielhelligkeit
        $this->RegisterPropertyBoolean('UseDefaultDim', true); // Helligkeit beim Einschalten setzen?
        $this->RegisterPropertyBoolean('StartEnabled', true);
        $this->RegisterPropertyBoolean('AutoOffOnManual', false);

        // Status-Variablen (Raum/Haus; Inhibit/Require)
        $this->RegisterPropertyString('RoomInhibit', '[]');  // [{var:int}]
        $this->RegisterPropertyString('HouseInhibit', '[]'); // [{var:int}]
        $this->RegisterPropertyString('RoomRequire', '[]');  // [{var:int}]
        $this->RegisterPropertyString('HouseRequire', '[]'); // [{var:int}]

        // Lux (optional)
        $this->RegisterPropertyInteger('LuxVar', 0); // VariableID eines Helligkeitssensors
        $this->RegisterPropertyInteger('LuxMax', 50);
        $this->RegisterPropertyBoolean('UseLux', true);

        // Adaptive Lux Learning
        $this->RegisterPropertyBoolean('AdaptiveLearningEnabled', false);
        $this->RegisterPropertyInteger('LearningAlpha', 2); // step size in lx per feedback
        $this->RegisterPropertyInteger('HysteresisPct', 10); // % hysteresis (reserved)

        // ---- Profiles ----
        $this->ensureProfiles();

        // Debugging
        $this->RegisterPropertyBoolean('DebugEnabled', false);

        // ---- Runtime variables ----
        $this->RegisterVariableBoolean('Enabled', 'Bewegungserkennung aktiv', '~Switch', 1);
        $this->EnableAction('Enabled');
        // Startzustand direkt bei Create setzen
        @SetValueBoolean($this->GetIDForIdent('Enabled'), (bool)$this->ReadPropertyBoolean('StartEnabled'));

        $this->RegisterVariableInteger('CountdownSec', 'Auto-Off Restzeit (s)', 'RMLDEV2.Seconds', 2);

        $this->RegisterVariableInteger('Set_TimeoutSec', 'Timeout (s)', 'RMLDEV2.TimeoutSec', 7);
        $this->EnableAction('Set_TimeoutSec');

        $this->RegisterVariableInteger('Set_DefaultDim', 'Standard-Helligkeit (%)', '~Intensity.100', 8);
        $this->EnableAction('Set_DefaultDim');
        // Lux-Schwelle als Variable für IPSView
        $this->RegisterVariableInteger('Set_LuxMax', 'Lux-Schwelle (≤)', '', 24);
        $this->EnableAction('Set_LuxMax');
        @SetValueInteger($this->GetIDForIdent('Set_LuxMax'), (int)$this->ReadPropertyInteger('LuxMax'));

        // Status-Indicatoren (read-only)
        $this->RegisterVariableBoolean('RoomInhibitActive', 'Raum-Blocker aktiv', 'RMLDEV2.Block', 3);
        $this->RegisterVariableBoolean('HouseInhibitActive', 'Haus-Blocker aktiv', 'RMLDEV2.Block', 4);
        $this->RegisterVariableBoolean('RequireSatisfied', 'Freigabe erfüllt/OK', 'RMLDEV2.Passed', 5);
        $this->RegisterVariableBoolean('LuxOK', 'Lux-Bedingung OK', 'RMLDEV2.Passed', 6);

        // ---- Timers ----
        $this->RegisterTimer('AutoOff', 0, 'RMLDEV2_AutoOff($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CountdownTick', 0, 'RMLDEV2_CountdownTick($_IPS[\'TARGET\']);');

        // ---- Additional Status Variables ----
        $this->RegisterVariableBoolean('EffectiveCanAutoOn', 'Auto-Einschalten erlaubt', 'RMLDEV2.Passed', 9);
        $this->RegisterVariableInteger('Mode', 'Entscheidungsmodus', 'RMLDEV2.Mode', 10);
        $this->RegisterVariableString('BlockReason', 'Sperrgrund / Hinweis', '', 11);
        $this->RegisterVariableBoolean('RequireNeeded', 'Freigaben konfiguriert', 'RMLDEV2.Passed', 12);
        $this->RegisterVariableInteger('LuxAtDecision', 'Lux beim Entscheid', '', 13);
        $this->RegisterVariableInteger('InhibitMatchedVar', 'Inhibit: auslösende VarID', '', 14);
        $this->RegisterVariableInteger('RequireMatchedVar', 'Require: erfüllende VarID', '', 15);
        $this->RegisterVariableInteger('NextAutoOffTS', 'Nächster Auto-Off', '~UnixTimestamp', 16);
        $this->RegisterVariableBoolean('AutoOffRunning', 'Auto-Off aktiv', '~Switch', 17);
        $this->RegisterVariableString('LastDecision', 'Letzte Entscheidung', '', 18);
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 19);
        $this->RegisterVariableInteger('LastSwitchSource', 'Letzte Quelle', 'RMLDEV2.Source', 20);
        $this->RegisterVariableInteger('LastDimTargetPct', 'Letzter Ziel-Dimm (%)', '~Intensity.100', 21);
        $this->RegisterVariableString('DecisionJSON', 'Diagnose JSON', '', 22);
        $this->RegisterVariableString('EventLog', 'Ereignis-Log (letzte 20)', '', 23);

        // ---- Attributes ----
        $this->RegisterAttributeInteger('AutoOffUntil', 0);
        $this->RegisterAttributeString('RegisteredIDs', '[]');
        $this->RegisterAttributeString('LuxLearn', ''); // JSON state for adaptive learning

        // ---- Initial Defaults ----
        @SetValueBoolean($this->GetIDForIdent('EffectiveCanAutoOn'), false);
        @SetValueInteger($this->GetIDForIdent('Mode'), 4);
        @SetValueString($this->GetIDForIdent('BlockReason'), '(Init)');
        @SetValueBoolean($this->GetIDForIdent('RequireNeeded'), false);
        @SetValueInteger($this->GetIDForIdent('LuxAtDecision'), 0);
        @SetValueInteger($this->GetIDForIdent('InhibitMatchedVar'), 0);
        @SetValueInteger($this->GetIDForIdent('RequireMatchedVar'), 0);
        @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), 0);
        @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), false);
        @SetValueString($this->GetIDForIdent('LastDecision'), '');
        @SetValueString($this->GetIDForIdent('LastAction'), 'none');
        @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 0);
        @SetValueInteger($this->GetIDForIdent('LastDimTargetPct'), 0);
        @SetValueString($this->GetIDForIdent('DecisionJSON'), '{}');
        @SetValueString($this->GetIDForIdent('EventLog'), '');

        // Adaptive Learning UI/State variables
        $this->RegisterVariableBoolean('LearningEnabled', 'Adaptives Lux-Lernen aktiv', '~Switch', 30);
        $this->EnableAction('LearningEnabled');
        @SetValueBoolean($this->GetIDForIdent('LearningEnabled'), (bool)$this->ReadPropertyBoolean('AdaptiveLearningEnabled'));

        $this->RegisterVariableString('LuxWindow', 'Aktuelles Zeitfenster', '', 31);
        $this->RegisterVariableInteger('LuxThresholdEffective', 'Lux-Schwelle (effektiv)', '', 32);
        $this->RegisterVariableInteger('Lux_Morning', 'Lux-Schwelle – Morgen', '', 33);
        $this->RegisterVariableInteger('Lux_Day', 'Lux-Schwelle – Tag', '', 34);
        $this->RegisterVariableInteger('Lux_Evening', 'Lux-Schwelle – Abend', '', 35);
        $this->RegisterVariableInteger('Lux_Night', 'Lux-Schwelle – Nacht', '', 36);
        $this->RegisterVariableInteger('Lux_Confidence', 'Lern-Sicherheit (%)', '~Intensity.100', 37);
        $this->RegisterVariableString('LastFeedback', 'Letztes Feedback', '', 38);
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
        // Register to Light variables (Status + Dimmer)
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            if ($sv > 0 && @IPS_VariableExists($sv)) {
                $this->RegisterMessage($sv, self::VM_UPDATE);
                $new[] = $sv;
            }
            $dv = (int)($l['dimmerVar'] ?? 0);
            if ($dv > 0 && @IPS_VariableExists($dv)) {
                $this->RegisterMessage($dv, self::VM_UPDATE);
                $new[] = $dv;
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
        @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), false);
        @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), 0);

        @SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), max(5, min(3600, (int)$this->ReadPropertyInteger('TimeoutSec'))));
        @SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), max(1, min(100, (int)$this->ReadPropertyInteger('DefaultDimPct'))));
        @SetValueInteger($this->GetIDForIdent('Set_LuxMax'), max(0, (int)$this->ReadPropertyInteger('LuxMax')));

        $this->initLearningIfNeeded();
        $this->updateLearningIndicators();
        $this->updateStatusIndicators();
    }

    /* ================= Configuration Form ================= */
    public function GetConfigurationForm(): string
    {
        // Build configuration form array to allow insertion of panel after "Lux (optional)"
        $elements = [
            ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [[
                'type' => 'List', 'name' => 'MotionVars', 'caption' => 'Melder',
                'columns' => [[
                    'caption' => 'Variable', 'name' => 'var', 'width' => '80%',
                    'add' => 0, 'edit' => ['type' => 'SelectVariable']
                ]],
                'add' => true, 'delete' => true
            ]]],
            ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [[
                'type' => 'List', 'name' => 'Lights', 'caption' => 'Akteure',
                'columns' => [
                    [
                        'caption' => 'Ein/Aus-Variable',
                        'name' => 'switchVar',
                        'width' => '80%',
                        'add' => 0,
                        'edit' => ['type' => 'SelectVariable']
                    ],
                    [
                        'caption' => 'Helligkeits-Variable (Intensity.100)',
                        'name' => 'dimmerVar',
                        'width' => '80%',
                        'add' => 0,
                        'edit' => ['type' => 'SelectVariable']
                    ]
                ],
                'add' => true, 'delete' => true
            ]]],
            ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                ['type' => 'CheckBox', 'name' => 'UseLux', 'caption' => 'Lux berücksichtigen'],
                ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable', 'enabled' => (bool)$this->ReadPropertyBoolean('UseLux')],
                ['type' => 'NumberSpinner',  'name' => 'LuxMax', 'caption' => 'Wenn Lux niedriger als ⇒ schalten', 'minimum' => 0, 'maximum' => 100000, 'enabled' => (bool)$this->ReadPropertyBoolean('UseLux')]
            ]],
            // Adaptive Learning panel inserted here
            ['type' => 'ExpansionPanel', 'caption' => 'Adaptives Lux-Lernen (Beta)', 'items' => [
                ['type' => 'CheckBox', 'name' => 'AdaptiveLearningEnabled', 'caption' => 'Lernen aktivieren'],
                ['type' => 'NumberSpinner', 'name' => 'LearningAlpha', 'caption' => 'Lernschritt (Lux)', 'minimum' => 1, 'maximum' => 50, 'enabled' => (bool)$this->ReadPropertyBoolean('AdaptiveLearningEnabled')],
                ['type' => 'NumberSpinner', 'name' => 'HysteresisPct', 'caption' => 'Hysterese (%)', 'minimum' => 0, 'maximum' => 50, 'enabled' => (bool)$this->ReadPropertyBoolean('AdaptiveLearningEnabled')],
                ['type' => 'Label', 'caption' => 'Hinweis: In diesem Schritt wird nur die effektive Lux-Schwelle pro Zeitfenster verwendet. Lernen (Feedback) folgt im nächsten Schritt.']
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Bedingungen: Blocker & Freigaben', 'items' => [
                ['type' => 'Label', 'caption' => 'Blocker (nicht schalten bei TRUE)'],
                ['type' => 'List', 'name' => 'RoomInhibit', 'caption' => 'Raum – Blocker',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '80%',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ],
                ['type' => 'List', 'name' => 'HouseInhibit', 'caption' => 'Haus – Blocker',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '80%',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ],
                ['type' => 'Label', 'caption' => 'Freigaben (nur schalten bei TRUE)'],
                ['type' => 'List', 'name' => 'RoomRequire', 'caption' => 'Raum – Freigaben',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '80%',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ],
                ['type' => 'List', 'name' => 'HouseRequire', 'caption' => 'Haus – Freigaben',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '80%',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ]
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Einstellungen', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'TimeoutSec', 'caption' => 'Timeout (Sekunden)', 'minimum' => 5, 'maximum' => 3600],
                ['type' => 'CheckBox', 'name' => 'UseDefaultDim', 'caption' => 'Helligkeit beim Einschalten setzen'],
                ['type' => 'NumberSpinner', 'name' => 'DefaultDimPct', 'caption' => 'Standard-Helligkeit (%)', 'minimum' => 1, 'maximum' => 100, 'enabled' => (bool)$this->ReadPropertyBoolean('UseDefaultDim')],
                ['type' => 'CheckBox', 'name' => 'StartEnabled', 'caption' => 'Beim Start aktivieren'],
                ['type' => 'CheckBox', 'name' => 'AutoOffOnManual', 'caption' => 'Auto-Off auch bei manuellem Einschalten'],
                ['type' => 'CheckBox', 'name' => 'DebugEnabled', 'caption' => 'Debug-Ausgabe aktivieren'],
            ]]
        ];
        return json_encode([
            'elements' => $elements,
            'actions'  => [
                [
                    'type'    => 'Button',
                    'caption' => 'Debug Snapshot ausgeben',
                    'onClick' => 'RMLDEV2_DebugDump($id);'
                ]
            ],
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
                    @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), false);
                    @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), 0);
                }
                $this->updateStatusIndicators();
                break;
            case 'LearningEnabled':
                SetValueBoolean($this->GetIDForIdent('LearningEnabled'), (bool)$Value);
                IPS_SetProperty($this->InstanceID, 'AdaptiveLearningEnabled', (bool)$Value);
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'Set_TimeoutSec':
                $val = max(5, min(3600, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), $val);
                // Wenn Timer aktiv ist, neu armieren
                if ($this->ReadAttributeInteger('AutoOffUntil') > time()) {
                    $this->armAutoOffTimer();
                }
                break;
            case 'Set_DefaultDim':
                $val = max(1, min(100, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), $val);
                break;
            case 'Set_LuxMax':
                $val = max(0, (int)$Value);
                SetValueInteger($this->GetIDForIdent('Set_LuxMax'), $val);
                IPS_SetProperty($this->InstanceID, 'LuxMax', $val);
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'DebugDump':
                $this->DebugDump();
                break;
            default:
                trigger_error("RequestAction: Unbekannter Ident $Ident", E_USER_WARNING);
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
                $ev = $this->evaluateAutoOn();
                if ($ev['canAutoOn']) {
                    $this->switchLights(true);
                    $this->armAutoOffTimer();
                    $targetPct = $this->ReadPropertyBoolean('UseDefaultDim') ? $this->getDefaultDimPct() : 0;
                    @SetValueString($this->GetIDForIdent('LastDecision'), 'Bewegung erkannt → Licht an (Ziel '.$targetPct.'%)');
                    @SetValueString($this->GetIDForIdent('LastAction'), 'ON');
                    @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 1);
                    @SetValueInteger($this->GetIDForIdent('LastDimTargetPct'), $targetPct);
                    $this->writeDecision($ev + ['event'=>'motion_true','action'=>'on','targetPct'=>$targetPct]);
                } else {
                    @SetValueString($this->GetIDForIdent('LastDecision'), 'Bewegung erkannt → kein Einschalten: '.$ev['reason']);
                    @SetValueString($this->GetIDForIdent('LastAction'), 'none');
                    @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 1);
                    @SetValueInteger($this->GetIDForIdent('LastDimTargetPct'), 0);
                    $this->writeDecision($ev + ['event'=>'motion_true','action'=>'none']);
                }
                $this->updateStatusIndicators();
            }
            return;
        }

        // Manual changes on light variables:
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            $dv = (int)($l['dimmerVar'] ?? 0);

            if ($sv > 0 && $SenderID === $sv) {
                $on = (bool)@GetValueBoolean($sv);
                if ($on) {
                    // Nur wenn gewünscht und Bedingungen erfüllt
                    $armed = false;
                    if ($this->ReadPropertyBoolean('AutoOffOnManual') && $this->shouldAllowAutoOff()) {
                        $this->armAutoOffIfIdle();
                        $armed = true;
                    }
                    @SetValueString($this->GetIDForIdent('LastDecision'), 'Manuell eingeschaltet → '.($armed ? 'Timer gestartet' : 'kein Timer'));
                    @SetValueString($this->GetIDForIdent('LastAction'), 'ON');
                    @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 2);
                    $this->writeDecision(['event'=>'manual_switch_on','action'=>'on','timerArmed'=>$armed]);
                } else {
                    // Beim manuellen Ausschalten Timer nur stoppen, wenn keine Bewegung mehr aktiv
                    if (!$this->isAnyMotionActive()) {
                        $this->SetTimerInterval('AutoOff', 0);
                        $this->SetTimerInterval('CountdownTick', 0);
                        $this->WriteAttributeInteger('AutoOffUntil', 0);
                        @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
                        @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), false);
                        @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), 0);
                        @SetValueString($this->GetIDForIdent('LastAction'), 'OFF');
                        @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 2);
                        $this->writeDecision(['event'=>'manual_switch_off','action'=>'off']);
                    }
                }
                return;
            } elseif ($dv > 0 && $SenderID === $dv) {
                // Dimmer manuell verändert → wenn irgendein Schalter "an" ist, Timer optional (AutoOffOnManual) und nur wenn erlaubt
                $anyOn = false;
                foreach ($this->getLights() as $ll) {
                    $ssv = (int)($ll['switchVar'] ?? 0);
                    if ($ssv > 0 && @IPS_VariableExists($ssv) && (bool)@GetValueBoolean($ssv)) {
                        $anyOn = true;
                        break;
                    }
                }
                if ($anyOn && $this->ReadPropertyBoolean('AutoOffOnManual') && $this->shouldAllowAutoOff()) {
                    $this->armAutoOffIfIdle();
                    @SetValueString($this->GetIDForIdent('LastDecision'), 'Manuell gedimmt → Timer gestartet');
                    @SetValueString($this->GetIDForIdent('LastAction'), 'ON');
                    @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 2);
                    $this->writeDecision(['event'=>'manual_dim','action'=>'on','timerArmed'=>true]);
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
            @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), false);
            @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), 0);
            @SetValueString($this->GetIDForIdent('LastDecision'), 'Auto-Off → Lichter aus');
            @SetValueString($this->GetIDForIdent('LastAction'), 'OFF');
            @SetValueInteger($this->GetIDForIdent('LastSwitchSource'), 3);
            $this->writeDecision(['event'=>'autooff','action'=>'off']);
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
        @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), $until);
        @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), true);
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
        $ev = $this->evaluateAutoOn();
        @SetValueBoolean($this->GetIDForIdent('RoomInhibitActive'), $ev['roomInhibit']);
        @SetValueBoolean($this->GetIDForIdent('HouseInhibitActive'), $ev['houseInhibit']);
        @SetValueBoolean($this->GetIDForIdent('RequireSatisfied'), $ev['requireSatisfied']);
        @SetValueBoolean($this->GetIDForIdent('LuxOK'), $ev['luxOk']);
        @SetValueBoolean($this->GetIDForIdent('EffectiveCanAutoOn'), $ev['canAutoOn']);
        @SetValueInteger($this->GetIDForIdent('Mode'), $ev['mode']);
        @SetValueString($this->GetIDForIdent('BlockReason'), $ev['reason']);
        @SetValueBoolean($this->GetIDForIdent('RequireNeeded'), $ev['requireNeeded']);
        @SetValueInteger($this->GetIDForIdent('InhibitMatchedVar'), $ev['inhibitMatched']);
        @SetValueInteger($this->GetIDForIdent('RequireMatchedVar'), $ev['requireMatched']);
        if ($ev['luxUsed'] && !is_null($ev['luxValue'])) {
            @SetValueInteger($this->GetIDForIdent('LuxAtDecision'), (int)$ev['luxValue']);
        } else {
            @SetValueInteger($this->GetIDForIdent('LuxAtDecision'), 0);
        }
        // AutoOffRunning, NextAutoOffTS
        $autoOffUntil = (int)$this->ReadAttributeInteger('AutoOffUntil');
        @SetValueBoolean($this->GetIDForIdent('AutoOffRunning'), $autoOffUntil > time());
        @SetValueInteger($this->GetIDForIdent('NextAutoOffTS'), $autoOffUntil);
        $this->updateLearningIndicators();
    }
    /**
     * Returns the first id in the list with boolean value TRUE, or 0 if none.
     */
    private function getFirstTrue(array $ids): int
    {
        foreach ($ids as $id) {
            if (@GetValueBoolean($id)) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Evaluates all gating and returns array with keys:
     *  roomInhibit, houseInhibit, inhibitMatched, roomRequire, houseRequire,
     *  requireNeeded, requireSatisfied, requireMatched,
     *  luxUsed, luxValue, luxOk, canAutoOn, mode, reason
     */
    private function evaluateAutoOn(): array
    {
        $roomInhibitList = $this->getBoolVarList('RoomInhibit');
        $houseInhibitList = $this->getBoolVarList('HouseInhibit');
        $roomRequireList = $this->getBoolVarList('RoomRequire');
        $houseRequireList = $this->getBoolVarList('HouseRequire');
        $roomInhibit = $this->anyTrue($roomInhibitList);
        $houseInhibit = $this->anyTrue($houseInhibitList);
        $inhibitMatched = $this->getFirstTrue(array_merge($roomInhibitList, $houseInhibitList));
        $requireNeeded = (count($roomRequireList) + count($houseRequireList)) > 0;
        $requireSatisfied = $this->anyTrue($roomRequireList) || $this->anyTrue($houseRequireList);
        $requireMatched = $this->getFirstTrue(array_merge($roomRequireList, $houseRequireList));
        $luxUsed = $this->isLuxConfigured();
        $luxValue = null;
        if ($luxUsed) {
            $luxVar = $this->getLuxVar();
            $luxValue = $luxVar > 0 ? (int)@GetValue($luxVar) : null;
        }
        $luxOk = (!$luxUsed) || ($luxValue !== null && $luxValue <= $this->getLuxMax());
        // Decision
        $mode = 0;
        $canAutoOn = true;
        $reason = 'OK';
        if (!$this->isEnabled()) {
            $mode = 4;
            $canAutoOn = false;
            $reason = 'Instanz deaktiviert';
        } elseif ($roomInhibit || $houseInhibit) {
            $mode = 1;
            $canAutoOn = false;
            $reason = 'Blocker aktiv (VarID=' . $inhibitMatched . ')';
        } elseif ($requireNeeded && !$requireSatisfied) {
            $mode = 2;
            $canAutoOn = false;
            $reason = 'Freigabe fehlt';
        } elseif ($luxUsed && !$luxOk) {
            $mode = 3;
            $canAutoOn = false;
            $reason = 'Lux zu hoch (' . $luxValue . '>' . $this->getLuxMax() . ')';
        }
        return [
            'roomInhibit' => $roomInhibit,
            'houseInhibit' => $houseInhibit,
            'inhibitMatched' => $inhibitMatched,
            'roomRequire' => $roomRequireList,
            'houseRequire' => $houseRequireList,
            'requireNeeded' => $requireNeeded,
            'requireSatisfied' => $requireSatisfied,
            'requireMatched' => $requireMatched,
            'luxUsed' => $luxUsed,
            'luxValue' => $luxValue,
            'luxOk' => $luxOk,
            'canAutoOn' => $canAutoOn,
            'mode' => $mode,
            'reason' => $reason
        ];
    }

    /**
     * Appends an event log line, prepending timestamp, and trims to last 20 lines.
     */
    private function appendEventLog(string $line): void
    {
        $ts = date('H:i:s');
        $entry = $ts . ' ' . $line;
        $id = $this->GetIDForIdent('EventLog');
        $prev = @GetValueString($id);
        $lines = array_filter(explode("\n", (string)$prev), 'strlen');
        $lines[] = $entry;
        // keep only last 20
        $lines = array_slice($lines, -20);
        @SetValueString($id, implode("\n", $lines));
    }

    /**
     * Stores decision array as JSON and appends a short log entry.
     */
    private function writeDecision(array $ev): void
    {
        @SetValueString($this->GetIDForIdent('DecisionJSON'), json_encode($ev, JSON_UNESCAPED_UNICODE));
        $short = '';
        if (isset($ev['event'])) {
            $short .= $ev['event'];
        }
        if (isset($ev['action'])) {
            $short .= ' → ' . $ev['action'];
        }
        if (isset($ev['targetPct'])) {
            $short .= ' (' . $ev['targetPct'] . '%)';
        }
        if (isset($ev['reason'])) {
            $short .= ' [' . $ev['reason'] . ']';
        }
        $this->appendEventLog(trim($short));
    }

    /* ================= Helpers ================= */
    private function switchLights(bool $on): void
    {
        $targetPct = $this->getDefaultDimPct();
        foreach ($this->getLights() as $l) {
            $sv = (int)($l['switchVar'] ?? 0);
            $dv = (int)($l['dimmerVar'] ?? 0);

            if ($sv > 0 && @IPS_VariableExists($sv)) {
                @RequestAction($sv, $on);
            }

            // Beim Einschalten – falls Dimmer vorhanden – auf Ziel-Pegel setzen (optional)
            if ($on && (bool)$this->ReadPropertyBoolean('UseDefaultDim') && $dv > 0 && @IPS_VariableExists($dv)) {
                $current = @GetValueInteger($dv);
                if (!is_int($current)) {
                    $current = (int)$current;
                }
                if ($targetPct > 0 && $current !== $targetPct) {
                    // Dimmer-Variable hat Profil Intensity.100 (0..100) → wir geben Prozent direkt
                    @RequestAction($dv, $targetPct);
                }
            }
        }
    }

    private function getTimeoutSec(): int
    {
        $id = @$this->GetIDForIdent('Set_TimeoutSec');
        if ($id && IPS_VariableExists($id)) {
            $v = (int)@GetValueInteger($id);
            if ($v >= 5 && $v <= 3600) {
                return $v;
            }
        }
        $t = (int)$this->ReadPropertyInteger('TimeoutSec');
        return max(5, min(3600, $t));
    }

    private function getDefaultDimPct(): int
    {
        $id = @$this->GetIDForIdent('Set_DefaultDim');
        if ($id && IPS_VariableExists($id)) {
            $v = (int)@GetValueInteger($id);
            if ($v >= 1 && $v <= 100) {
                return $v;
            }
        }
        $p = (int)$this->ReadPropertyInteger('DefaultDimPct');
        return max(1, min(100, $p));
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
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $row) {
            $out[] = [
                'switchVar' => (int)($row['switchVar'] ?? 0),
                'dimmerVar' => (int)($row['dimmerVar'] ?? 0),
            ];
        }
        return $out;
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

    private function shouldAllowAutoOff(): bool
    {
        // Auto-Off (bei manuellem Einschalten) ist nur erlaubt,
        // wenn nicht blockiert und – falls Erfordern-Listen vorhanden – mindestens eine TRUE ist.
        if ($this->anyTrue($this->getBoolVarList('RoomInhibit')) || $this->anyTrue($this->getBoolVarList('HouseInhibit'))) {
            return false;
        }
        $roomReq  = $this->getBoolVarList('RoomRequire');
        $houseReq = $this->getBoolVarList('HouseRequire');
        if ((count($roomReq) + count($houseReq)) > 0) {
            if (!($this->anyTrue($roomReq) || $this->anyTrue($houseReq))) {
                return false;
            }
        }
        // Lux spielt für Auto-Off bei manueller Bedienung keine Rolle
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
        $currentLux = (float)$raw;
        $thr = (float)$this->getEffectiveLuxThreshold();
        // (Hysterese ist für Schritt 1 noch nicht aktiv verwendet)
        return $currentLux <= $thr;
    }

    /* ================= Debug Snapshot ================= */
    public function DebugDump(): void
    {
        $this->dbg('--- DEBUG SNAPSHOT START ---');

        // Bewegungsmelder
        $m = $this->getMotionVars();
        $mStates = [];
        foreach ($m as $vid) {
            $mStates[] = ['id'=>$vid, 'val'=>(int)@GetValueBoolean($vid)];
        }
        $this->dbg('MotionVars='.json_encode($mStates));

        // Lichter
        $lights = $this->getLights();
        $ls = [];
        foreach ($lights as $a) {
            $sv = (int)($a['switchVar'] ?? 0);
            $dv = (int)($a['dimmerVar'] ?? 0);
            $ls[] = [
                'switchVar'=>$sv,
                'switchVal'=> $sv>0? (int)@GetValueBoolean($sv) : null,
                'dimmerVar'=>$dv,
                'dimmerVal'=> $dv>0? (int)@GetValueInteger($dv) : null
            ];
        }
        $this->dbg('Lights='.json_encode($ls));

        // Statusbereiche
        $this->dbg('RoomInhibit='.json_encode($this->getBoolVarList('RoomInhibit')));
        $this->dbg('HouseInhibit='.json_encode($this->getBoolVarList('HouseInhibit')));
        $this->dbg('RoomRequire='.json_encode($this->getBoolVarList('RoomRequire')));
        $this->dbg('HouseRequire='.json_encode($this->getBoolVarList('HouseRequire')));

        // Lux
        $luxFeat = (bool)$this->ReadPropertyBoolean('UseLux');
        $luxVar  = (int)$this->ReadPropertyInteger('LuxVar');
        $luxMax  = (int)$this->ReadPropertyInteger('LuxMax');
        $luxVal  = ($luxVar>0 && @IPS_VariableExists($luxVar)) ? (int)@GetValue($luxVar) : null;
        $this->dbg('Lux: Feature='.($luxFeat?'ON':'OFF').' Var='.$luxVar.' Val='.(is_null($luxVal)?'n/a':$luxVal).' Max='.$luxMax);

        // Settings
        $this->dbg('Settings: TimeoutSec='.$this->getTimeoutSec(). ' DefaultDimPct='.$this->getDefaultDimPct().' UseDefaultDim='.(int)$this->ReadPropertyBoolean('UseDefaultDim').' AutoOffOnManual='.(int)$this->ReadPropertyBoolean('AutoOffOnManual'));

        // Timerstatus
        $until = (int)$this->ReadAttributeInteger('AutoOffUntil');
        $remain = max(0, $until - time());
        $this->dbg('Timer: AutoOffUntil='.$until.' (remain='.$remain.'s)');

        $this->dbg('--- DEBUG SNAPSHOT END ---');
    }

    /* ================= Debug helpers ================= */
    private function dbg($msg)
    {
        if ($this->ReadPropertyBoolean('DebugEnabled')) {
            $this->SendDebug('RMLDEV2', $msg, 0);
        }
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
            IPS_SetVariableProfileAssociation('RMLDEV2.Passed', 0, 'Freigabe fehlt', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Passed', 1, 'Freigabe OK', '', -1);
        }
        // Einfaches Sekunden-Profil für Integer: zeigt "XYZ s"
        if (!IPS_VariableProfileExists('RMLDEV2.Seconds')) {
            IPS_CreateVariableProfile('RMLDEV2.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RMLDEV2.Seconds', 0);
            IPS_SetVariableProfileText('RMLDEV2.Seconds', '', ' s');
            IPS_SetVariableProfileValues('RMLDEV2.Seconds', 0, 86400, 1);
        }
        // Integer profile for TimeoutSec
        if (!IPS_VariableProfileExists('RMLDEV2.TimeoutSec')) {
            IPS_CreateVariableProfile('RMLDEV2.TimeoutSec', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RMLDEV2.TimeoutSec', 0);
            IPS_SetVariableProfileText('RMLDEV2.TimeoutSec', '', ' s');
            IPS_SetVariableProfileValues('RMLDEV2.TimeoutSec', 5, 3600, 5);
        }

        // Integer profile for Mode
        if (!IPS_VariableProfileExists('RMLDEV2.Mode')) {
            IPS_CreateVariableProfile('RMLDEV2.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('RMLDEV2.Mode', 0, 'OK', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Mode', 1, 'Blocker', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Mode', 2, 'Freigabe fehlt', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Mode', 3, 'Lux zu hoch', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Mode', 4, 'Deaktiviert', '', -1);
        }
        // Integer profile for Source
        if (!IPS_VariableProfileExists('RMLDEV2.Source')) {
            IPS_CreateVariableProfile('RMLDEV2.Source', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('RMLDEV2.Source', 0, 'keine', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Source', 1, 'Bewegung', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Source', 2, 'Manuell', '', -1);
            IPS_SetVariableProfileAssociation('RMLDEV2.Source', 3, 'Timer', '', -1);
        }
    }
}
    /* ================= Adaptive Learning (Step 1: storage & effective threshold) ================= */
    private function initLearningIfNeeded(): void
    {
        $stateRaw = $this->ReadAttributeString('LuxLearn');
        $state = @json_decode($stateRaw, true);
        if (!is_array($state) || !isset($state['windows'])) {
            $def = (int)$this->ReadPropertyInteger('LuxMax');
            $state = [
                'windows' => [
                    'morning' => ['thr'=>$def, 'samples'=>0, 'confidence'=>0.0],
                    'day'     => ['thr'=>$def, 'samples'=>0, 'confidence'=>0.0],
                    'evening' => ['thr'=>$def, 'samples'=>0, 'confidence'=>0.0],
                    'night'   => ['thr'=>$def, 'samples'=>0, 'confidence'=>0.0],
                ],
                'lastUpdate' => time()
            ];
            $this->WriteAttributeString('LuxLearn', json_encode($state));
        }
    }

    private function getCurrentWindowKey(): string
    {
        $h = (int)date('G');
        if ($h >= 6 && $h < 9) return 'morning';
        if ($h >= 9 && $h < 18) return 'day';
        if ($h >= 18 && $h < 22) return 'evening';
        return 'night';
    }

    private function loadLearnState(): array
    {
        $raw = $this->ReadAttributeString('LuxLearn');
        $arr = @json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    private function saveLearnState(array $state): void
    {
        $this->WriteAttributeString('LuxLearn', json_encode($state));
    }

    private function getEffectiveLuxThreshold(): int
    {
        $this->initLearningIfNeeded();
        $useAdaptive = (bool)$this->ReadPropertyBoolean('AdaptiveLearningEnabled');
        if (!$useAdaptive) {
            $thr = (int)$this->ReadPropertyInteger('LuxMax');
            @SetValueInteger($this->GetIDForIdent('LuxThresholdEffective'), $thr);
            @SetValueString($this->GetIDForIdent('LuxWindow'), '-');
            return $thr;
        }
        $state = $this->loadLearnState();
        $win = $this->getCurrentWindowKey();
        $thr = (int)($state['windows'][$win]['thr'] ?? (int)$this->ReadPropertyInteger('LuxMax'));
        @SetValueInteger($this->GetIDForIdent('LuxThresholdEffective'), $thr);
        @SetValueString($this->GetIDForIdent('LuxWindow'), $win);
        return $thr;
    }

    private function updateLearningIndicators(): void
    {
        $this->initLearningIfNeeded();
        $state = $this->loadLearnState();
        $win = $this->getCurrentWindowKey();
        $thrM = (int)($state['windows']['morning']['thr'] ?? 0);
        $thrD = (int)($state['windows']['day']['thr'] ?? 0);
        $thrE = (int)($state['windows']['evening']['thr'] ?? 0);
        $thrN = (int)($state['windows']['night']['thr'] ?? 0);
        @SetValueInteger($this->GetIDForIdent('Lux_Morning'), $thrM);
        @SetValueInteger($this->GetIDForIdent('Lux_Day'), $thrD);
        @SetValueInteger($this->GetIDForIdent('Lux_Evening'), $thrE);
        @SetValueInteger($this->GetIDForIdent('Lux_Night'), $thrN);
        $conf = (int)round(100 * (float)($state['windows'][$win]['confidence'] ?? 0.0));
        @SetValueInteger($this->GetIDForIdent('Lux_Confidence'), max(0, min(100, $conf)));
        // Effective threshold & window
        $this->getEffectiveLuxThreshold();
    }