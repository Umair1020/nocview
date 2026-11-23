<?php
// File: /public_html/config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Constants (no cache constants: all dashboard data is read live)

// Performance optimization for real-time data
ini_set('max_execution_time', 30);
ini_set('memory_limit', '512M');
ini_set('default_socket_timeout', 10);
gc_enable();

// Add this to your update_cache.php before any output
session_write_close(); // Release session lock for concurrent requests

$clientConfig = [
    'ziauddin' => [
        'MT_USER' => 'spotcomm',
        'MT_PASS' => 'admin@1234',
        'locations' => [
            [
               'ip' => '103.246.109.194',
                'name' => 'Ziauddin Hospital Clifton & University',
                'lat' => 24.817330982261044,
                'lng' => 67.00741565348838,
                'connectivity' => '200 Mbps',
                'deployment_date' => '4th Feb 2025',
                'address' => '4/B Shahrah-e-Ghalib Rd, Block 6 Clifton, Karachi, Karachi City, Sindh 75600, Pakistan',
                'status' => 'See dashboard for live monitoring status.',
                'ip_details' => '103.246.109.194',
                'NM' => '255.255.255.192',
                'GW' => '',   // Clifton Sangfor 5504 eth5 (.194/26); gateway not confirmed yet
                'DNS' => '',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '',
            ],
            [
                'ip' => '103.246.109.2',
                'name' => 'Ziauddin Hospital North Nazimabad',
                'lat' => 24.924176733382097,
                'lng' => 67.04516806562982,
                'connectivity' => '200 Mbps',
                'deployment_date' => '4th Feb 2025',
                'address' => 'Allama Rasheed Turabi Rd, Wahid Colony, Karachi, Karachi City, Sindh 74700, Pakistan',
                'status' => 'Fiber work completed and switches have been deployed. Link is active now.',
                'ip_details' => '103.246.109.2-62',
                'NM' => '255.255.255.192',
                'GW' => '103.246.109.1',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.108.50',
                'name' => 'Ziauddin Hospital Korangi Creek',
                'lat' => 24.8293084028347,
                'lng' =>  66.98415034697024,
                'connectivity' => '80 Mbps',
                'deployment_date' => '27th Jan 2025',
                'address' => 'Korangi creek opp. ILMA University, Karachi, Pakistan',
                'status' => 'Link Completed and Active. IPs assigned and provided as well.',
                'ip_details' => '103.246.108.50-24 ',
                'NM' => '255.255.255.248',
                'GW' => '103.246.108.49',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.74',
                'name' => 'ZCR Boat Basin (Clifton)',
                'lat' => 24.821866951122317,
                'lng' => 67.02390280634931,
                'connectivity' => '50 Mbps',
                'deployment_date' => '7th Feb 2025',
                'address' => 'F 6, Block 5 Clifton, Karachi, Karachi City, Sindh 75600, Pakistan',
                'status' => 'Link is live now.',
                'ip_details' => '103.246.109.74-78',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.73',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.82',
                'name' => 'Ziauddin Education Board (ZEB)',
                'lat' => 24.816475118848693,
                'lng' => 67.00343062203713,
                'connectivity' => '50 Mbps',
                'deployment_date' => '10th Feb 2025',
                'address' => '54, opposite Ziauddin University College of Rehabilitation Science, Block-1 Block 1 Clifton, Karachi, Karachi City, Sindh, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.82-86',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.81',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.90',
                'name' => 'ZUFEST North University',
                'lat' => 24.92785287660052,
                'lng' => 67.04169822514763,
                'connectivity' => '100 Mbps',
                'deployment_date' => '8th Feb 2025',
                'address' => 'F-103, Block-B Block B North Nazimabad Town, Karachi, Sindh 74600, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.90-94',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.89',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            // [
            //     'ip' => '103.246.109.98',
            //     'name' => 'Nursing - North',
            //     'lat' => 24.924751213588884,
            //     'lng' => 67.0469246127083,
            //     'connectivity' => '100 Mbps',
            //     'deployment_date' => '4th Feb 2025',
            //     'address' => 'Kausar Niazi Colony, Karachi, Karachi City, Sindh, Pakistan',
            //     'status' => 'Link is Live now.',
            //     'ip_details' => '103.246.109.98-102',
            //     'NM' => '255.255.255.248',
            //     'GW' => '103.246.109.97',
            //     'DNS' => '118.103.239.9',
            //     'S.DNS' => '8.8.8.8',
            //     'Vlan' => '64',
            // ],
            [
                'ip' => '103.246.109.106',
                'name' => 'ZCRS / MT / FM (North)',
                'lat' => 24.923866591144378,
                'lng' => 67.04618931227398,
                'connectivity' => '50 Mbps',
                'deployment_date' => '4th Feb 2025',
                'address' => 'E/40/1/1 Wahid Colony, Karachi, Karachi City, Sindh 74600, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.106-110 ',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.105',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.114',
                'name' => 'College of Intermediate',
                'lat' => 24.934360339676896,
                'lng' => 67.05420393690044,
                'connectivity' => '30 Mbps',
                'deployment_date' => '11th Feb 2025',
                'address' => 'Allama Rasheed Turabi Rd, Block F North Nazimabad Town, Karachi, Karachi City, Sindh, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.114-118',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.113',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.122',
                'name' => 'KDLB - Kemari Hospital',
                'lat' => 24.825890614238787,
                'lng' => 66.98262685398043,
                'connectivity' => '50 Mbps',
                'deployment_date' => '25th Feb 2025',
                'address' => 'Plot 33 Hospitals Rd, behind Karachi Port Trust Hospital, East Wharf Port Of Karachi, Karachi, Karachi City, Sindh, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.122-126',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.121',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.130',
                'name' => 'College of SLT',
                'lat' => 24.817950,
                'lng' => 67.008080,
                'connectivity' => '20 Mbps',
                'deployment_date' => '6th Feb 2025',
                'address' => 'Khayban-e-Bedil, Block-1 Block 1 Clifton, Karachi, Karachi City, Sindh, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.130-134',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.129',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.138',
                'name' => 'MD - Home',
                'lat' => 24.972871,
                'lng' => 67.064332,
                'connectivity' => '30 Mbps',
                'deployment_date' => '6th Feb 2025',
                'address' => 'North Nazimabad Town, Karachi, Pakistan',
                'status' => 'Link is Live now.',
                'ip_details' => '103.246.109.138-142 ',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.137',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.146',
                'name' => 'SLT Clinic',
                'lat' => 24.817950,
                'lng' => 67.008080,
                'connectivity' => '50 Mbps',
                'deployment_date' => '7th Feb 2025',
                'address' => 'Block 6, Clifton, Karachi, Karachi City, Sindh, Pakistan',
                'status' => 'Link is live now.',
                'ip_details' => '103.246.109.146-150 ',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.145',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
            [
                'ip' => '103.246.109.154',
                'name' => 'Ziauddin Memorial Hospital',
                'lat' => 24.91517803114236,
                'lng' => 67.02649216258823,
                'connectivity' => '10 Mbps',
                'deployment_date' => '12th Feb 2025',
                'address' => 'Nazimabad 3 Block 3 Nazimabad, Karachi, Karachi City, Sindh 74600, Pakistan',
                'status' => 'Link is live now.',
                'ip_details' => '103.246.109.154-158 ',
                'NM' => '255.255.255.248',
                'GW' => '103.246.109.153',
                'DNS' => '118.103.239.9',
                'S.DNS' => '8.8.8.8',
                'Vlan' => '64',
            ],
        ],

         'pingLocations' => [
            '103.246.109.194' => 'Ziauddin Hospital Clifton & University',
            '103.246.109.2'   => 'Ziauddin North Nazimabad',
            '103.246.109.74'  => 'ZCR Boat Basin (Clifton)',
            '103.246.109.82'  => 'Ziauddin Education Board (ZEB)',
        ],
        'pingInterfaces' => [
            '103.246.109.194' => 'eth5',   // Clifton - Sangfor 5504
            '103.246.109.2'   => 'eth4',   // North Nazimabad - Sangfor 5505 (ZIA-H.O)
            '103.246.109.74'  => 'eth4',   // ZCR Boat Basin - Sangfor
            '103.246.109.82'  => 'eth3',   // ZEB - Sangfor NSF-1200-A
        ],
        'locationMap' => [
            // '103.246.109.98' => 'Nursing - North',
            '103.246.109.106' => 'ZCRS / MT / FM (North)',
            '103.246.109.114' => 'College of Intermediate',
            '103.246.109.122' => 'KDLB - Kemari Hospital',
            '103.246.109.130' => 'College of SLT',
            '103.246.109.138' => 'MD - Home',
            '103.246.109.146' => 'SLT Clinic',
            '103.246.109.154' => 'Ziauddin Memorial Hospital',
            '103.246.108.50' => 'Ziauddin Hospital Korangi Creek',
            '103.246.109.90' => 'Zu Fest North University',
        ],
        'interfaceMap' => [
            // '103.246.109.98' => 'ether3- Spotcomm',
            '103.246.109.106' => 'ether3-Spottcomm',
            '103.246.109.114' => 'ether5-spotcomm',
            '103.246.109.122' => 'ether1-WAN',
            '103.246.109.130' => 'ether4-Spot-Com',
            '103.246.109.138' => 'ether6-Spotcomm',
            '103.246.109.146' => 'ether4-SpotCom',
            '103.246.109.154' => 'ether5-Spotcomm',
            '103.246.108.50' => 'ether2-LAN',
            '103.246.109.90' => 'ether3-Spotcomm',
            '115.42.66.56' => 'ether1'
        ]
    ],
    'Knoativ' => [
        'MT_USER' => 'api',
        'MT_PASS' => 'compaq123_',
        'GWN_BASE_URL' => "https://www.gwn.cloud",
        'GWN_APP_ID' => "667380", 
        'GWN_SECRET_KEY' => "IkSX55UXZpx4eDEbDtbn64dUYx0ubNs4",
        'GWN_USERNAME' => "ccwificloud",
        'GWN_PASSWORD' => "compaq123_",
        'GWN_NETWORK_ID' => "38678",
        'locations' => [
            [
                'ip' => '115.42.67.210',
                'name' => 'Knoativ Office',
                'lat' => 24.872960,
                'lng' => 67.060889,
                'connectivity' => '300 Mbps',
                'deployment_date' => '1st Mar 2025',
                'address' => 'Office # 305-306 2nd Floor, Tariq Center, Main Tariq Road P.E.C.H.S Block 2 , Karachi , Pakistan',
                'status' => 'Link is Live and Active',
                'ip_details' => '115.42.67.210-220',
                'NM' => '255.255.255.240',
                'GW' => '115.42.67.209',
                'DNS' => '8.8.8.8',
                'S.DNS' => '8.8.4.4',
                'Vlan' => '10',
            ]
        ],
         'locationMap' => [
        '115.42.67.210' => 'Knoativ', // صرف یہی ایک لوکیشن
    ],
    'interfaceMap' => [
        '115.42.67.210_ether1' => 'ether1',
        '115.42.67.210_ether2' => 'ether2',
        '115.42.67.210_bridge' => 'LAN BRIDGE'
    ],
      'gwn_ap_mapping' => [
        'D17 TR IT ROOM' => '193.168.99.22',
        'D11 TR IT Room' => '193.168.99.26',
        'D12 TR RECEPTION' => '193.168.99.27',
        'D13 TR CAFETERIA' => '193.168.99.28',
        'D14 TR DM ROOM' => '193.168.99.29',
        'D15 TR HALL 2' => '193.168.99.30',
        'D16 TR HALL 1' => '193.168.99.31',
        'RJ D1 HALL 1' => '193.168.99.17',
        'RJ D2 HALL1' => '193.168.99.16',
        'RJ D3 HALL 1' => '193.168.99.18',
        'D4 HALL 1' => '193.168.99.19',
        'D5 RECEPTION' => '193.168.99.20',
        'D6 CONFERENCE ROOM' => '193.168.99.21',
        'D10 HALL 2' => '193.168.99.24',
        'D7 PRODUCT' => '193.168.99.25',
        'D8 HALL 2' => '193.168.99.23',
        'D9 HALL 2 BACKUP' => '193.168.99.100'
      ],
    'interfaceLocations' => [ // نیا array صرف bandwidth.php کے لیے
        '115.42.67.210_ether1' => 'Knoativ - ether1',
        '115.42.67.210_ether2' => 'Knoativ - ether2',
        '115.42.67.210_bridge' => 'Knoativ - bridge'
    ]
    ]
];


// config.php سے لوکیشن کی معلومات حاصل کرنے کے لیے PHP سے متغیر منتقل کریں


function getClientForUsername($username) {
    global $clientConfig;

    // Map users to clients (same rules as before, but without touching the session)
    if (in_array($username, ['ziauddin', 'khurram', 'noman', 'admin'], true)) {
        return $clientConfig['ziauddin'];
    } elseif ($username === 'Knoativ') {
        return $clientConfig['Knoativ'];
    }
    // Default to ziauddin
    return $clientConfig['ziauddin'];
}

function getCurrentClient() {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $username = $_SESSION['username'] ?? 'ziauddin';
    return getClientForUsername($username);
}

function getClientLocations() {
    $client = getCurrentClient();
    return $client['locations'];
}
?>