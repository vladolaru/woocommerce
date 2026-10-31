<?php
/**
 * WooPaymentsExpressCheckoutAddressNormalizer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WP_REST_Request;

/**
 * Normalizes the addresses express checkout wallets send to the Store API (state keys, Hong Kong regions, address
 * lines and redacted postcodes) before Store API validation, for requests that carry a valid tokenized cart nonce.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsExpressCheckoutAddressNormalizer {

	/**
	 * The tokenized cart nonce action, owned by the tokenized cart session controller.
	 */
	private const TOKENIZED_CART_NONCE_ACTION = WooPaymentsTokenizedCartSessionController::TOKENIZED_CART_NONCE_ACTION;

	private const TOKENIZED_CART_HEADER = 'X-WooPayments-Tokenized-Cart';

	private const TOKENIZED_CART_NONCE_HEADER = 'X-WooPayments-Tokenized-Cart-Nonce';

	private const HONG_KONG_REGION_CANDIDATES = array(
		'HONG KONG'       => array(
			'hong kong',
			'hongkong',
			'hong kong island',
			'港島',
			'central and western',
			'中西區',
			'kennedy town',
			'shek tong tsui',
			'sai ying pun',
			'sheung wan',
			'central',
			'admiralty',
			'mid-levels',
			'peak',
			'堅尼地城',
			'石塘咀',
			'西營盤',
			'上環',
			'中環',
			'金鐘',
			'半山區',
			'山頂',
			'wan chai',
			'灣仔',
			'causeway bay',
			'happy valley',
			'tai hang',
			'so kon po',
			"jardine's lookout",
			'銅鑼灣',
			'跑馬地',
			'大坑',
			'掃桿埔',
			'渣甸山',
			'eastern',
			'東區',
			'tin hau',
			'braemar hill',
			'north point',
			'quarry bay',
			'sai wan ho',
			'shau kei wan',
			'chai wan',
			'siu sai wan',
			'天后',
			'寶馬山',
			'北角',
			'鰂魚涌',
			'西灣河',
			'筲箕灣',
			'柴灣',
			'小西灣',
			'southern',
			'南區',
			'pok fu lam',
			'aberdeen',
			'ap lei chau',
			'wong chuk hang',
			'shouson hill',
			'repulse bay',
			'chung hom kok',
			'stanley',
			'tai tam',
			'shek o',
			'薄扶林',
			'香港仔',
			'鴨脷洲',
			'黃竹坑',
			'壽臣山',
			'淺水灣',
			'舂磡角',
			'赤柱',
			'大潭',
			'石澳',
		),
		'KOWLOON'         => array(
			'kowloon',
			'九龍',
			'yau tsim mong',
			'油尖旺',
			'tsim sha tsui',
			'yau ma tei',
			'west kowloon reclamation',
			"king's park, mong kok",
			'tai kok tsui',
			'尖沙咀',
			'油麻地',
			'西九龍填海區',
			'京士柏',
			'mong kok',
			'旺角',
			'大角咀',
			'sham shui po',
			'深水埗',
			'mei foo',
			'lai chi kok',
			'cheung sha wan',
			'shek kip mei',
			'yau yat tsuen',
			'tai wo ping',
			'stonecutters island',
			'美孚',
			'荔枝角',
			'長沙灣',
			'石硤尾',
			'又一村',
			'大窩坪',
			'昂船洲',
			'kowloon city',
			'九龍城',
			'hung hom',
			'to kwa wan',
			'ma tau kok',
			'ma tau wai',
			'kai tak',
			'ho man tin',
			'kowloon tong',
			'beacon hill',
			'紅磡',
			'土瓜灣',
			'馬頭角',
			'馬頭圍',
			'啟德',
			'何文田',
			'九龍塘',
			'筆架山',
			'wong tai sin',
			'黃大仙',
			'san po kong',
			'tung tau',
			'wang tau hom',
			'lok fu',
			'diamond hill',
			'tsz wan shan',
			'ngau chi wan',
			'新蒲崗',
			'東頭',
			'橫頭磡',
			'樂富',
			'鑽石山',
			'慈雲山',
			'牛池灣',
			'kwun tong',
			'觀塘',
			'ping shek',
			'kowloon bay',
			'ngau tau kok',
			'jordan valley',
			'sau mau ping',
			'lam tin',
			'yau tong',
			'lei yue mun',
			'坪石',
			'九龍灣',
			'牛頭角',
			'佐敦谷',
			'秀茂坪',
			'藍田',
			'油塘',
			'鯉魚門',
		),
		'NEW TERRITORIES' => array(
			'new territories',
			'新界',
			'kwai tsing',
			'葵青',
			'kwai chung',
			'tsing yi',
			'葵涌',
			'青衣',
			'tsuen wan',
			'荃灣',
			'lei muk shue',
			'ting kau',
			'sham tseng',
			'tsing lung tau',
			'ma wan',
			'sunny bay',
			'梨木樹',
			'汀九',
			'深井',
			'青龍頭',
			'馬灣',
			'欣澳',
			'tuen mun',
			'屯門',
			'tai lam chung',
			'so kwun wat',
			'lam tei',
			'大欖涌',
			'掃管笏',
			'藍地',
			'yuen long',
			'元朗',
			'hung shui kiu',
			'ha tsuen',
			'lau fau shan',
			'tin shui wai',
			'san tin',
			'lok ma chau',
			'kam tin',
			'shek kong',
			'pat heung',
			'洪水橋',
			'廈村',
			'流浮山',
			'天水圍',
			'新田',
			'落馬洲',
			'錦田',
			'石崗',
			'八鄉',
			'north',
			'north district',
			'北區',
			'fanling',
			'luen wo hui',
			'sheung shui',
			'shek wu hui',
			'sha tau kok',
			'luk keng',
			'wu kau tang',
			'粉嶺',
			'聯和墟',
			'上水',
			'石湖墟',
			'沙頭角',
			'鹿頸',
			'烏蛟騰',
			'tai po',
			'大埔',
			'tai po market',
			'tai po kau',
			'tai mei tuk',
			'shuen wan',
			'cheung muk tau',
			'kei ling ha',
			'大埔墟',
			'大埔滘',
			'大尾篤',
			'船灣',
			'樟木頭',
			'企嶺下',
			'sha tin',
			'沙田',
			'tai wai',
			'fo tan',
			'ma liu shui',
			'wu kai sha',
			'ma on shan',
			'大圍',
			'火炭',
			'馬料水',
			'烏溪沙',
			'馬鞍山',
			'sai kung',
			'西貢',
			'clear water bay',
			'tai mong tsai',
			'tseung kwan o',
			'hang hau',
			'tiu keng leng',
			'ma yau tong',
			'清水灣',
			'大網仔',
			'將軍澳',
			'坑口',
			'調景嶺',
			'馬游塘',
			'islands',
			'離島',
			'cheung chau',
			'peng chau',
			'lantau island (including tung chung)',
			'lamma island',
			'長洲',
			'坪洲',
			'大嶼山(包括東涌)',
			'南丫島',
		),
	);

	/**
	 * Wallet state values by country and WooCommerce state key: the code, the name and the local name (null when there
	 * is none) that Chromium's address dialog offers, from Google's libaddressinput. Countries without states are left
	 * out. Keys follow WooCommerce's current state keys: Chiang Mai is TH-50, Chiang Rai TH-57 and Odisha OD.
	 *
	 * @var array<string,array<string,array{0:string,1:string,2:string|null}>>
	 */
	private const EXPRESS_CHECKOUT_STATES = array(
		'AR' => array(
			'C' => array( 'Ciudad Autónoma de Buenos Aires', 'Ciudad Autónoma de Buenos Aires', null ),
			'B' => array( 'Buenos Aires', 'Buenos Aires', null ),
			'K' => array( 'Catamarca', 'Catamarca', null ),
			'H' => array( 'Chaco', 'Chaco', null ),
			'U' => array( 'Chubut', 'Chubut', null ),
			'X' => array( 'Córdoba', 'Córdoba', null ),
			'W' => array( 'Corrientes', 'Corrientes', null ),
			'E' => array( 'Entre Ríos', 'Entre Ríos', null ),
			'P' => array( 'Formosa', 'Formosa', null ),
			'Y' => array( 'Jujuy', 'Jujuy', null ),
			'L' => array( 'La Pampa', 'La Pampa', null ),
			'F' => array( 'La Rioja', 'La Rioja', null ),
			'M' => array( 'Mendoza', 'Mendoza', null ),
			'N' => array( 'Misiones', 'Misiones', null ),
			'Q' => array( 'Neuquén', 'Neuquén', null ),
			'R' => array( 'Río Negro', 'Río Negro', null ),
			'A' => array( 'Salta', 'Salta', null ),
			'J' => array( 'San Juan', 'San Juan', null ),
			'D' => array( 'San Luis', 'San Luis', null ),
			'Z' => array( 'Santa Cruz', 'Santa Cruz', null ),
			'S' => array( 'Santa Fe', 'Santa Fe', null ),
			'G' => array( 'Santiago del Estero', 'Santiago del Estero', null ),
			'V' => array( 'Tierra del Fuego', 'Tierra del Fuego', null ),
			'T' => array( 'Tucumán', 'Tucumán', null ),
		),
		'AU' => array(
			'ACT' => array( 'ACT', 'Australian Capital Territory', null ),
			'NSW' => array( 'NSW', 'New South Wales', null ),
			'NT'  => array( 'NT', 'Northern Territory', null ),
			'QLD' => array( 'QLD', 'Queensland', null ),
			'SA'  => array( 'SA', 'South Australia', null ),
			'TAS' => array( 'TAS', 'Tasmania', null ),
			'VIC' => array( 'VIC', 'Victoria', null ),
			'WA'  => array( 'WA', 'Western Australia', null ),
		),
		'BR' => array(
			'AC' => array( 'AC', 'Acre', null ),
			'AL' => array( 'AL', 'Alagoas', null ),
			'AP' => array( 'AP', 'Amapá', null ),
			'AM' => array( 'AM', 'Amazonas', null ),
			'BA' => array( 'BA', 'Bahia', null ),
			'CE' => array( 'CE', 'Ceará', null ),
			'DF' => array( 'DF', 'Distrito Federal', null ),
			'ES' => array( 'ES', 'Espírito Santo', null ),
			'GO' => array( 'GO', 'Goiás', null ),
			'MA' => array( 'MA', 'Maranhão', null ),
			'MT' => array( 'MT', 'Mato Grosso', null ),
			'MS' => array( 'MS', 'Mato Grosso do Sul', null ),
			'MG' => array( 'MG', 'Minas Gerais', null ),
			'PA' => array( 'PA', 'Pará', null ),
			'PB' => array( 'PB', 'Paraíba', null ),
			'PR' => array( 'PR', 'Paraná', null ),
			'PE' => array( 'PE', 'Pernambuco', null ),
			'PI' => array( 'PI', 'Piauí', null ),
			'RJ' => array( 'RJ', 'Rio de Janeiro', null ),
			'RN' => array( 'RN', 'Rio Grande do Norte', null ),
			'RS' => array( 'RS', 'Rio Grande do Sul', null ),
			'RO' => array( 'RO', 'Rondônia', null ),
			'RR' => array( 'RR', 'Roraima', null ),
			'SC' => array( 'SC', 'Santa Catarina', null ),
			'SP' => array( 'SP', 'São Paulo', null ),
			'SE' => array( 'SE', 'Sergipe', null ),
			'TO' => array( 'TO', 'Tocantins', null ),
		),
		'CA' => array(
			'AB' => array( 'AB', 'Alberta', 'Alberta' ),
			'BC' => array( 'BC', 'British Columbia', 'Colombie-Britannique' ),
			'MB' => array( 'MB', 'Manitoba', 'Manitoba' ),
			'NB' => array( 'NB', 'New Brunswick', 'Nouveau-Brunswick' ),
			'NL' => array( 'NL', 'Newfoundland and Labrador', 'Terre-Neuve-et-Labrador' ),
			'NT' => array( 'NT', 'Northwest Territories', 'Territoires du Nord-Ouest' ),
			'NS' => array( 'NS', 'Nova Scotia', 'Nouvelle-Écosse' ),
			'NU' => array( 'NU', 'Nunavut', 'Nunavut' ),
			'ON' => array( 'ON', 'Ontario', 'Ontario' ),
			'PE' => array( 'PE', 'Prince Edward Island', 'Île-du-Prince-Édouard' ),
			'QC' => array( 'QC', 'Quebec', 'Québec' ),
			'SK' => array( 'SK', 'Saskatchewan', 'Saskatchewan' ),
			'YT' => array( 'YT', 'Yukon', 'Yukon' ),
		),
		'CN' => array(
			'CN1'  => array( 'Yunnan Sheng', 'Yunnan Sheng', '云南省' ),
			'CN2'  => array( 'Beijing Shi', 'Beijing Shi', '北京市' ),
			'CN3'  => array( 'Tianjin Shi', 'Tianjin Shi', '天津市' ),
			'CN4'  => array( 'Hebei Sheng', 'Hebei Sheng', '河北省' ),
			'CN5'  => array( 'Shanxi Sheng', 'Shanxi Sheng', '山西省' ),
			'CN6'  => array( 'Neimenggu Zizhiqu', 'Neimenggu Zizhiqu', '内蒙古' ),
			'CN7'  => array( 'Liaoning Sheng', 'Liaoning Sheng', '辽宁省' ),
			'CN8'  => array( 'Jilin Sheng', 'Jilin Sheng', '吉林省' ),
			'CN9'  => array( 'Heilongjiang Sheng', 'Heilongjiang Sheng', '黑龙江省' ),
			'CN10' => array( 'Shanghai Shi', 'Shanghai Shi', '上海市' ),
			'CN11' => array( 'Jiangsu Sheng', 'Jiangsu Sheng', '江苏省' ),
			'CN12' => array( 'Zhejiang Sheng', 'Zhejiang Sheng', '浙江省' ),
			'CN13' => array( 'Anhui Sheng', 'Anhui Sheng', '安徽省' ),
			'CN14' => array( 'Fujian Sheng', 'Fujian Sheng', '福建省' ),
			'CN15' => array( 'Jiangxi Sheng', 'Jiangxi Sheng', '江西省' ),
			'CN16' => array( 'Shandong Sheng', 'Shandong Sheng', '山东省' ),
			'CN17' => array( 'Henan Sheng', 'Henan Sheng', '河南省' ),
			'CN18' => array( 'Hubei Sheng', 'Hubei Sheng', '湖北省' ),
			'CN19' => array( 'Hunan Sheng', 'Hunan Sheng', '湖南省' ),
			'CN20' => array( 'Guangdong Sheng', 'Guangdong Sheng', '广东省' ),
			'CN21' => array( 'Guangxi Zhuangzuzizhiqu', 'Guangxi Zhuangzuzizhiqu', '广西' ),
			'CN22' => array( 'Hainan Sheng', 'Hainan Sheng', '海南省' ),
			'CN23' => array( 'Chongqing Shi', 'Chongqing Shi', '重庆市' ),
			'CN24' => array( 'Sichuan Sheng', 'Sichuan Sheng', '四川省' ),
			'CN25' => array( 'Guizhou Sheng', 'Guizhou Sheng', '贵州省' ),
			'CN26' => array( 'Shaanxi Sheng', 'Shaanxi Sheng', '陕西省' ),
			'CN27' => array( 'Gansu Sheng', 'Gansu Sheng', '甘肃省' ),
			'CN28' => array( 'Qinghai Sheng', 'Qinghai Sheng', '青海省' ),
			'CN29' => array( 'Ningxia Huizuzizhiqu', 'Ningxia Huizuzizhiqu', '宁夏' ),
			'CN30' => array( 'Macau', 'Macau', '澳门' ),
			'CN31' => array( 'Xizang Zizhiqu', 'Xizang Zizhiqu', '西藏' ),
			'CN32' => array( 'Xinjiang Weiwuerzizhiqu', 'Xinjiang Weiwuerzizhiqu', '新疆' ),
		),
		'EG' => array(
			'EGALX' => array( 'Alexandria Governorate', 'Alexandria Governorate', 'الإسكندرية' ),
			'EGASN' => array( 'Aswan Governorate', 'Aswan Governorate', 'أسوان' ),
			'EGAST' => array( 'Asyut Governorate', 'Asyut Governorate', 'أسيوط' ),
			'EGBA'  => array( 'Red Sea Governorate', 'Red Sea Governorate', 'البحر الأحمر' ),
			'EGBH'  => array( 'El Beheira Governorate', 'El Beheira Governorate', 'البحيرة' ),
			'EGBNS' => array( 'Beni Suef Governorate', 'Beni Suef Governorate', 'بني سويف' ),
			'EGC'   => array( 'Cairo Governorate', 'Cairo Governorate', 'القاهرة' ),
			'EGDK'  => array( 'Dakahlia Governorate', 'Dakahlia Governorate', 'الدقهلية' ),
			'EGDT'  => array( 'Damietta Governorate', 'Damietta Governorate', 'دمياط' ),
			'EGFYM' => array( 'Faiyum Governorate', 'Faiyum Governorate', 'الفيوم' ),
			'EGGH'  => array( 'Gharbia Governorate', 'Gharbia Governorate', 'الغربية' ),
			'EGGZ'  => array( 'Giza Governorate', 'Giza Governorate', 'الجيزة' ),
			'EGIS'  => array( 'Ismailia Governorate', 'Ismailia Governorate', 'الإسماعيلية' ),
			'EGJS'  => array( 'South Sinai Governorate', 'South Sinai Governorate', 'جنوب سيناء' ),
			'EGKB'  => array( 'Qalyubia Governorate', 'Qalyubia Governorate', 'القليوبية' ),
			'EGKFS' => array( 'Kafr El Sheikh Governorate', 'Kafr El Sheikh Governorate', 'كفر الشيخ' ),
			'EGKN'  => array( 'Qena Governorate', 'Qena Governorate', 'قنا' ),
			'EGLX'  => array( 'Luxor Governorate', 'Luxor Governorate', 'الأقصر' ),
			'EGMN'  => array( 'Menia Governorate', 'Menia Governorate', 'المنيا' ),
			'EGMNF' => array( 'Menofia Governorate', 'Menofia Governorate', 'المنوفية' ),
			'EGMT'  => array( 'Matrouh Governorate', 'Matrouh Governorate', 'مطروح' ),
			'EGPTS' => array( 'Port Said Governorate', 'Port Said Governorate', 'بورسعيد' ),
			'EGSHG' => array( 'Sohag Governorate', 'Sohag Governorate', 'سوهاج' ),
			'EGSHR' => array( 'Ash Sharqia Governorate', 'Ash Sharqia Governorate', 'الشرقية' ),
			'EGSIN' => array( 'North Sinai Governorate', 'North Sinai Governorate', 'شمال سيناء' ),
			'EGSUZ' => array( 'Suez Governorate', 'Suez Governorate', 'السويس' ),
			'EGWAD' => array( 'New Valley Governorate', 'New Valley Governorate', 'الوادي الجديد' ),
		),
		'ES' => array(
			'C'  => array( 'A Coruña', 'A Coruña', null ),
			'VI' => array( 'Álava', 'Álava', null ),
			'AB' => array( 'Albacete', 'Albacete', null ),
			'A'  => array( 'Alicante', 'Alicante', null ),
			'AL' => array( 'Almería', 'Almería', null ),
			'O'  => array( 'Asturias', 'Asturias', null ),
			'AV' => array( 'Ávila', 'Ávila', null ),
			'BA' => array( 'Badajoz', 'Badajoz', null ),
			'PM' => array( 'Balears', 'Balears', null ),
			'B'  => array( 'Barcelona', 'Barcelona', null ),
			'BU' => array( 'Burgos', 'Burgos', null ),
			'CC' => array( 'Cáceres', 'Cáceres', null ),
			'CA' => array( 'Cádiz', 'Cádiz', null ),
			'S'  => array( 'Cantabria', 'Cantabria', null ),
			'CS' => array( 'Castellón', 'Castellón', null ),
			'CE' => array( 'Ceuta', 'Ceuta', null ),
			'CR' => array( 'Ciudad Real', 'Ciudad Real', null ),
			'CO' => array( 'Córdoba', 'Córdoba', null ),
			'CU' => array( 'Cuenca', 'Cuenca', null ),
			'GI' => array( 'Girona', 'Girona', null ),
			'GR' => array( 'Granada', 'Granada', null ),
			'GU' => array( 'Guadalajara', 'Guadalajara', null ),
			'SS' => array( 'Guipúzcoa', 'Guipúzcoa', null ),
			'H'  => array( 'Huelva', 'Huelva', null ),
			'HU' => array( 'Huesca', 'Huesca', null ),
			'J'  => array( 'Jaén', 'Jaén', null ),
			'LO' => array( 'La Rioja', 'La Rioja', null ),
			'GC' => array( 'Las Palmas', 'Las Palmas', null ),
			'LE' => array( 'León', 'León', null ),
			'L'  => array( 'Lleida', 'Lleida', null ),
			'LU' => array( 'Lugo', 'Lugo', null ),
			'M'  => array( 'Madrid', 'Madrid', null ),
			'MA' => array( 'Málaga', 'Málaga', null ),
			'ML' => array( 'Melilla', 'Melilla', null ),
			'MU' => array( 'Murcia', 'Murcia', null ),
			'NA' => array( 'Navarra', 'Navarra', null ),
			'OR' => array( 'Ourense', 'Ourense', null ),
			'P'  => array( 'Palencia', 'Palencia', null ),
			'PO' => array( 'Pontevedra', 'Pontevedra', null ),
			'SA' => array( 'Salamanca', 'Salamanca', null ),
			'TF' => array( 'Santa Cruz de Tenerife', 'Santa Cruz de Tenerife', null ),
			'SG' => array( 'Segovia', 'Segovia', null ),
			'SE' => array( 'Sevilla', 'Sevilla', null ),
			'SO' => array( 'Soria', 'Soria', null ),
			'T'  => array( 'Tarragona', 'Tarragona', null ),
			'TE' => array( 'Teruel', 'Teruel', null ),
			'TO' => array( 'Toledo', 'Toledo', null ),
			'V'  => array( 'Valencia', 'Valencia', null ),
			'VA' => array( 'Valladolid', 'Valladolid', null ),
			'BI' => array( 'Vizcaya', 'Vizcaya', null ),
			'ZA' => array( 'Zamora', 'Zamora', null ),
			'Z'  => array( 'Zaragoza', 'Zaragoza', null ),
		),
		'HK' => array(
			'HONG KONG'       => array( 'Hong Kong Island', 'Hong Kong Island', '香港島' ),
			'KOWLOON'         => array( 'Kowloon', 'Kowloon', '九龍' ),
			'NEW TERRITORIES' => array( 'New Territories', 'New Territories', '新界' ),
		),
		'ID' => array(
			'AC' => array( 'Aceh', 'Aceh', null ),
			'SU' => array( 'Sumatera Utara', 'Sumatera Utara', null ),
			'SB' => array( 'Sumatera Barat', 'Sumatera Barat', null ),
			'RI' => array( 'Riau', 'Riau', null ),
			'KR' => array( 'Kepulauan Riau', 'Kepulauan Riau', null ),
			'JA' => array( 'Jambi', 'Jambi', null ),
			'SS' => array( 'Sumatera Selatan', 'Sumatera Selatan', null ),
			'BB' => array( 'Kepulauan Bangka Belitung', 'Kepulauan Bangka Belitung', null ),
			'BE' => array( 'Bengkulu', 'Bengkulu', null ),
			'LA' => array( 'Lampung', 'Lampung', null ),
			'JK' => array( 'DKI Jakarta', 'DKI Jakarta', null ),
			'JB' => array( 'Jawa Barat', 'Jawa Barat', null ),
			'BT' => array( 'Banten', 'Banten', null ),
			'JT' => array( 'Jawa Tengah', 'Jawa Tengah', null ),
			'JI' => array( 'Jawa Timur', 'Jawa Timur', null ),
			'YO' => array( 'Daerah Istimewa Yogyakarta', 'Daerah Istimewa Yogyakarta', null ),
			'BA' => array( 'Bali', 'Bali', null ),
			'NB' => array( 'Nusa Tenggara Barat', 'Nusa Tenggara Barat', null ),
			'NT' => array( 'Nusa Tenggara Timur', 'Nusa Tenggara Timur', null ),
			'KB' => array( 'Kalimantan Barat', 'Kalimantan Barat', null ),
			'KT' => array( 'Kalimantan Tengah', 'Kalimantan Tengah', null ),
			'KI' => array( 'Kalimantan Timur', 'Kalimantan Timur', null ),
			'KS' => array( 'Kalimantan Selatan', 'Kalimantan Selatan', null ),
			'KU' => array( 'Kalimantan Utara', 'Kalimantan Utara', null ),
			'SA' => array( 'Sulawesi Utara', 'Sulawesi Utara', null ),
			'ST' => array( 'Sulawesi Tengah', 'Sulawesi Tengah', null ),
			'SG' => array( 'Sulawesi Tenggara', 'Sulawesi Tenggara', null ),
			'SR' => array( 'Sulawesi Barat', 'Sulawesi Barat', null ),
			'SN' => array( 'Sulawesi Selatan', 'Sulawesi Selatan', null ),
			'GO' => array( 'Gorontalo', 'Gorontalo', null ),
			'MA' => array( 'Maluku', 'Maluku', null ),
			'MU' => array( 'Maluku Utara', 'Maluku Utara', null ),
			'PA' => array( 'Papua', 'Papua', null ),
			'PB' => array( 'Papua Barat', 'Papua Barat', null ),
		),
		'IE' => array(
			'CW' => array( 'Co. Carlow', 'Co. Carlow', null ),
			'CN' => array( 'Co. Cavan', 'Co. Cavan', null ),
			'CE' => array( 'Co. Clare', 'Co. Clare', null ),
			'CO' => array( 'Co. Cork', 'Co. Cork', null ),
			'DL' => array( 'Co. Donegal', 'Co. Donegal', null ),
			'D'  => array( 'Co. Dublin', 'Co. Dublin', null ),
			'G'  => array( 'Co. Galway', 'Co. Galway', null ),
			'KY' => array( 'Co. Kerry', 'Co. Kerry', null ),
			'KE' => array( 'Co. Kildare', 'Co. Kildare', null ),
			'KK' => array( 'Co. Kilkenny', 'Co. Kilkenny', null ),
			'LS' => array( 'Co. Laois', 'Co. Laois', null ),
			'LM' => array( 'Co. Leitrim', 'Co. Leitrim', null ),
			'LK' => array( 'Co. Limerick', 'Co. Limerick', null ),
			'LD' => array( 'Co. Longford', 'Co. Longford', null ),
			'LH' => array( 'Co. Louth', 'Co. Louth', null ),
			'MO' => array( 'Co. Mayo', 'Co. Mayo', null ),
			'MH' => array( 'Co. Meath', 'Co. Meath', null ),
			'MN' => array( 'Co. Monaghan', 'Co. Monaghan', null ),
			'OY' => array( 'Co. Offaly', 'Co. Offaly', null ),
			'RN' => array( 'Co. Roscommon', 'Co. Roscommon', null ),
			'SO' => array( 'Co. Sligo', 'Co. Sligo', null ),
			'TA' => array( 'Co. Tipperary', 'Co. Tipperary', null ),
			'WD' => array( 'Co. Waterford', 'Co. Waterford', null ),
			'WH' => array( 'Co. Westmeath', 'Co. Westmeath', null ),
			'WX' => array( 'Co. Wexford', 'Co. Wexford', null ),
			'WW' => array( 'Co. Wicklow', 'Co. Wicklow', null ),
		),
		'IN' => array(
			'AP' => array( 'Andhra Pradesh', 'Andhra Pradesh', null ),
			'AR' => array( 'Arunachal Pradesh', 'Arunachal Pradesh', null ),
			'AS' => array( 'Assam', 'Assam', null ),
			'BR' => array( 'Bihar', 'Bihar', null ),
			'CT' => array( 'Chhattisgarh', 'Chhattisgarh', null ),
			'GA' => array( 'Goa', 'Goa', null ),
			'GJ' => array( 'Gujarat', 'Gujarat', null ),
			'HR' => array( 'Haryana', 'Haryana', null ),
			'HP' => array( 'Himachal Pradesh', 'Himachal Pradesh', null ),
			'JK' => array( 'Jammu and Kashmir', 'Jammu & Kashmir', null ),
			'JH' => array( 'Jharkhand', 'Jharkhand', null ),
			'KA' => array( 'Karnataka', 'Karnataka', null ),
			'KL' => array( 'Kerala', 'Kerala', null ),
			'MP' => array( 'Madhya Pradesh', 'Madhya Pradesh', null ),
			'MH' => array( 'Maharashtra', 'Maharashtra', null ),
			'MN' => array( 'Manipur', 'Manipur', null ),
			'ML' => array( 'Meghalaya', 'Meghalaya', null ),
			'MZ' => array( 'Mizoram', 'Mizoram', null ),
			'NL' => array( 'Nagaland', 'Nagaland', null ),
			'OD' => array( 'Odisha', 'Odisha', null ),
			'PB' => array( 'Punjab', 'Punjab', null ),
			'RJ' => array( 'Rajasthan', 'Rajasthan', null ),
			'SK' => array( 'Sikkim', 'Sikkim', null ),
			'TN' => array( 'Tamil Nadu', 'Tamil Nadu', null ),
			'TS' => array( 'Telangana', 'Telangana', null ),
			'TR' => array( 'Tripura', 'Tripura', null ),
			'UK' => array( 'Uttarakhand', 'Uttarakhand', null ),
			'UP' => array( 'Uttar Pradesh', 'Uttar Pradesh', null ),
			'WB' => array( 'West Bengal', 'West Bengal', null ),
			'AN' => array( 'Andaman and Nicobar Islands', 'Andaman & Nicobar', null ),
			'CH' => array( 'Chandigarh', 'Chandigarh', null ),
			'DN' => array( 'Dadra and Nagar Haveli', 'Dadra & Nagar Haveli', null ),
			'DD' => array( 'Daman and Diu', 'Daman & Diu', null ),
			'DL' => array( 'Delhi', 'Delhi', null ),
			'LD' => array( 'Lakshadweep', 'Lakshadweep', null ),
			'PY' => array( 'Puducherry', 'Puducherry', null ),
		),
		'IR' => array(
			'KHZ' => array( 'Khuzestan Province', 'Khuzestan Province', 'استان خوزستان' ),
			'THR' => array( 'Tehran Province', 'Tehran Province', 'استان تهران' ),
			'ILM' => array( 'Ilam Province', 'Ilam Province', 'استان ایلام' ),
			'BHR' => array( 'Bushehr Province', 'Bushehr Province', 'استان بوشهر' ),
			'ADL' => array( 'Ardabil Province', 'Ardabil Province', 'استان اردبیل' ),
			'ESF' => array( 'Isfahan Province', 'Isfahan Province', 'استان اصفهان' ),
			'YZD' => array( 'Yazd Province', 'Yazd Province', 'استان یزد' ),
			'KRH' => array( 'Kermanshah Province', 'Kermanshah Province', 'استان کرمانشاه' ),
			'KRN' => array( 'Kerman Province', 'Kerman Province', 'استان کرمان' ),
			'HDN' => array( 'Hamadan Province', 'Hamadan Province', 'استان همدان' ),
			'GZN' => array( 'Qazvin Province', 'Qazvin Province', 'استان قزوین' ),
			'ZJN' => array( 'Zanjan Province', 'Zanjan Province', 'استان زنجان' ),
			'LRS' => array( 'Lorestan Province', 'Lorestan Province', 'استان لرستان' ),
			'ABZ' => array( 'Alborz Province', 'Alborz Province', 'استان البرز' ),
			'EAZ' => array( 'East Azerbaijan Province', 'East Azerbaijan Province', 'استان آذربایجان شرقی' ),
			'WAZ' => array( 'West Azerbaijan Province', 'West Azerbaijan Province', 'استان آذربایجان غربی' ),
			'CHB' => array( 'Chaharmahal and Bakhtiari Province', 'Chaharmahal and Bakhtiari Province', 'استان چهارمحال و بختیاری' ),
			'SKH' => array( 'South Khorasan Province', 'South Khorasan Province', 'استان خراسان جنوبی' ),
			'RKH' => array( 'Razavi Khorasan Province', 'Razavi Khorasan Province', 'استان خراسان رضوی' ),
			'NKH' => array( 'North Khorasan Province', 'North Khorasan Province', 'استان خراسان شمالی' ),
			'SMN' => array( 'Semnan Province', 'Semnan Province', 'استان سمنان' ),
			'FRS' => array( 'Fars Province', 'Fars Province', 'استان فارس' ),
			'QHM' => array( 'Qom Province', 'Qom Province', 'استان قم' ),
			'KRD' => array( 'Kurdistan Province', 'Kurdistan Province', 'استان کردستان' ),
			'KBD' => array( 'Kohgiluyeh and Boyer-Ahmad Province', 'Kohgiluyeh and Boyer-Ahmad Province', 'استان کهگیلویه و بویراحمد' ),
			'GLS' => array( 'Golestan Province', 'Golestan Province', 'استان گلستان' ),
			'GIL' => array( 'Gilan Province', 'Gilan Province', 'استان گیلان' ),
			'MZN' => array( 'Mazandaran Province', 'Mazandaran Province', 'استان مازندران' ),
			'MKZ' => array( 'Markazi Province', 'Markazi Province', 'استان مرکزی' ),
			'HRZ' => array( 'Hormozgan Province', 'Hormozgan Province', 'استان هرمزگان' ),
			'SBN' => array( 'Sistan and Baluchestan Province', 'Sistan and Baluchestan Province', 'استان سیستان و بلوچستان' ),
		),
		'IT' => array(
			'AG' => array( 'AG', 'Agrigento', null ),
			'AL' => array( 'AL', 'Alessandria', null ),
			'AN' => array( 'AN', 'Ancona', null ),
			'AO' => array( 'AO', 'Aosta', null ),
			'AR' => array( 'AR', 'Arezzo', null ),
			'AP' => array( 'AP', 'Ascoli Piceno', null ),
			'AT' => array( 'AT', 'Asti', null ),
			'AV' => array( 'AV', 'Avellino', null ),
			'BA' => array( 'BA', 'Bari', null ),
			'BT' => array( 'BT', 'Barletta-Andria-Trani', null ),
			'BL' => array( 'BL', 'Belluno', null ),
			'BN' => array( 'BN', 'Benevento', null ),
			'BG' => array( 'BG', 'Bergamo', null ),
			'BI' => array( 'BI', 'Biella', null ),
			'BO' => array( 'BO', 'Bologna', null ),
			'BZ' => array( 'BZ', 'Bolzano', null ),
			'BS' => array( 'BS', 'Brescia', null ),
			'BR' => array( 'BR', 'Brindisi', null ),
			'CA' => array( 'CA', 'Cagliari', null ),
			'CL' => array( 'CL', 'Caltanissetta', null ),
			'CB' => array( 'CB', 'Campobasso', null ),
			'CE' => array( 'CE', 'Caserta', null ),
			'CT' => array( 'CT', 'Catania', null ),
			'CZ' => array( 'CZ', 'Catanzaro', null ),
			'CH' => array( 'CH', 'Chieti', null ),
			'CO' => array( 'CO', 'Como', null ),
			'CS' => array( 'CS', 'Cosenza', null ),
			'CR' => array( 'CR', 'Cremona', null ),
			'KR' => array( 'KR', 'Crotone', null ),
			'CN' => array( 'CN', 'Cuneo', null ),
			'EN' => array( 'EN', 'Enna', null ),
			'FM' => array( 'FM', 'Fermo', null ),
			'FE' => array( 'FE', 'Ferrara', null ),
			'FI' => array( 'FI', 'Firenze', null ),
			'FG' => array( 'FG', 'Foggia', null ),
			'FC' => array( 'FC', 'Forlì-Cesena', null ),
			'FR' => array( 'FR', 'Frosinone', null ),
			'GE' => array( 'GE', 'Genova', null ),
			'GO' => array( 'GO', 'Gorizia', null ),
			'GR' => array( 'GR', 'Grosseto', null ),
			'IM' => array( 'IM', 'Imperia', null ),
			'IS' => array( 'IS', 'Isernia', null ),
			'SP' => array( 'SP', 'La Spezia', null ),
			'AQ' => array( 'AQ', "L'Aquila", null ),
			'LT' => array( 'LT', 'Latina', null ),
			'LE' => array( 'LE', 'Lecce', null ),
			'LC' => array( 'LC', 'Lecco', null ),
			'LI' => array( 'LI', 'Livorno', null ),
			'LO' => array( 'LO', 'Lodi', null ),
			'LU' => array( 'LU', 'Lucca', null ),
			'MC' => array( 'MC', 'Macerata', null ),
			'MN' => array( 'MN', 'Mantova', null ),
			'MS' => array( 'MS', 'Massa-Carrara', null ),
			'MT' => array( 'MT', 'Matera', null ),
			'ME' => array( 'ME', 'Messina', null ),
			'MI' => array( 'MI', 'Milano', null ),
			'MO' => array( 'MO', 'Modena', null ),
			'MB' => array( 'MB', 'Monza e Brianza', null ),
			'NA' => array( 'NA', 'Napoli', null ),
			'NO' => array( 'NO', 'Novara', null ),
			'NU' => array( 'NU', 'Nuoro', null ),
			'OR' => array( 'OR', 'Oristano', null ),
			'PD' => array( 'PD', 'Padova', null ),
			'PA' => array( 'PA', 'Palermo', null ),
			'PR' => array( 'PR', 'Parma', null ),
			'PV' => array( 'PV', 'Pavia', null ),
			'PG' => array( 'PG', 'Perugia', null ),
			'PU' => array( 'PU', 'Pesaro e Urbino', null ),
			'PE' => array( 'PE', 'Pescara', null ),
			'PC' => array( 'PC', 'Piacenza', null ),
			'PI' => array( 'PI', 'Pisa', null ),
			'PT' => array( 'PT', 'Pistoia', null ),
			'PN' => array( 'PN', 'Pordenone', null ),
			'PZ' => array( 'PZ', 'Potenza', null ),
			'PO' => array( 'PO', 'Prato', null ),
			'RG' => array( 'RG', 'Ragusa', null ),
			'RA' => array( 'RA', 'Ravenna', null ),
			'RC' => array( 'RC', 'Reggio Calabria', null ),
			'RE' => array( 'RE', 'Reggio Emilia', null ),
			'RI' => array( 'RI', 'Rieti', null ),
			'RN' => array( 'RN', 'Rimini', null ),
			'RM' => array( 'RM', 'Roma', null ),
			'RO' => array( 'RO', 'Rovigo', null ),
			'SA' => array( 'SA', 'Salerno', null ),
			'SS' => array( 'SS', 'Sassari', null ),
			'SV' => array( 'SV', 'Savona', null ),
			'SI' => array( 'SI', 'Siena', null ),
			'SR' => array( 'SR', 'Siracusa', null ),
			'SO' => array( 'SO', 'Sondrio', null ),
			'SU' => array( 'SU', 'Sud Sardegna', null ),
			'TA' => array( 'TA', 'Taranto', null ),
			'TE' => array( 'TE', 'Teramo', null ),
			'TR' => array( 'TR', 'Terni', null ),
			'TO' => array( 'TO', 'Torino', null ),
			'TP' => array( 'TP', 'Trapani', null ),
			'TN' => array( 'TN', 'Trento', null ),
			'TV' => array( 'TV', 'Treviso', null ),
			'TS' => array( 'TS', 'Trieste', null ),
			'UD' => array( 'UD', 'Udine', null ),
			'VA' => array( 'VA', 'Varese', null ),
			'VE' => array( 'VE', 'Venezia', null ),
			'VB' => array( 'VB', 'Verbano-Cusio-Ossola', null ),
			'VC' => array( 'VC', 'Vercelli', null ),
			'VR' => array( 'VR', 'Verona', null ),
			'VV' => array( 'VV', 'Vibo Valentia', null ),
			'VI' => array( 'VI', 'Vicenza', null ),
			'VT' => array( 'VT', 'Viterbo', null ),
		),
		'JM' => array(
			'JM-01' => array( 'Kingston', 'Kingston', null ),
			'JM-02' => array( 'St. Andrew', 'St. Andrew', null ),
			'JM-03' => array( 'St. Thomas', 'St. Thomas', null ),
			'JM-04' => array( 'Portland', 'Portland', null ),
			'JM-05' => array( 'St. Mary', 'St. Mary', null ),
			'JM-06' => array( 'St. Ann', 'St. Ann', null ),
			'JM-07' => array( 'Trelawny', 'Trelawny', null ),
			'JM-08' => array( 'St. James', 'St. James', null ),
			'JM-09' => array( 'Hanover', 'Hanover', null ),
			'JM-10' => array( 'Westmoreland', 'Westmoreland', null ),
			'JM-11' => array( 'St. Elizabeth', 'St. Elizabeth', null ),
			'JM-12' => array( 'Manchester', 'Manchester', null ),
			'JM-13' => array( 'Clarendon', 'Clarendon', null ),
			'JM-14' => array( 'St. Catherine', 'St. Catherine', null ),
		),
		'JP' => array(
			'JP01' => array( 'Hokkaido', 'Hokkaido', '北海道' ),
			'JP02' => array( 'Aomori', 'Aomori', '青森県' ),
			'JP03' => array( 'Iwate', 'Iwate', '岩手県' ),
			'JP04' => array( 'Miyagi', 'Miyagi', '宮城県' ),
			'JP05' => array( 'Akita', 'Akita', '秋田県' ),
			'JP06' => array( 'Yamagata', 'Yamagata', '山形県' ),
			'JP07' => array( 'Fukushima', 'Fukushima', '福島県' ),
			'JP08' => array( 'Ibaraki', 'Ibaraki', '茨城県' ),
			'JP09' => array( 'Tochigi', 'Tochigi', '栃木県' ),
			'JP10' => array( 'Gunma', 'Gunma', '群馬県' ),
			'JP11' => array( 'Saitama', 'Saitama', '埼玉県' ),
			'JP12' => array( 'Chiba', 'Chiba', '千葉県' ),
			'JP13' => array( 'Tokyo', 'Tokyo', '東京都' ),
			'JP14' => array( 'Kanagawa', 'Kanagawa', '神奈川県' ),
			'JP15' => array( 'Niigata', 'Niigata', '新潟県' ),
			'JP16' => array( 'Toyama', 'Toyama', '富山県' ),
			'JP17' => array( 'Ishikawa', 'Ishikawa', '石川県' ),
			'JP18' => array( 'Fukui', 'Fukui', '福井県' ),
			'JP19' => array( 'Yamanashi', 'Yamanashi', '山梨県' ),
			'JP20' => array( 'Nagano', 'Nagano', '長野県' ),
			'JP21' => array( 'Gifu', 'Gifu', '岐阜県' ),
			'JP22' => array( 'Shizuoka', 'Shizuoka', '静岡県' ),
			'JP23' => array( 'Aichi', 'Aichi', '愛知県' ),
			'JP24' => array( 'Mie', 'Mie', '三重県' ),
			'JP25' => array( 'Shiga', 'Shiga', '滋賀県' ),
			'JP26' => array( 'Kyoto', 'Kyoto', '京都府' ),
			'JP27' => array( 'Osaka', 'Osaka', '大阪府' ),
			'JP28' => array( 'Hyogo', 'Hyogo', '兵庫県' ),
			'JP29' => array( 'Nara', 'Nara', '奈良県' ),
			'JP30' => array( 'Wakayama', 'Wakayama', '和歌山県' ),
			'JP31' => array( 'Tottori', 'Tottori', '鳥取県' ),
			'JP32' => array( 'Shimane', 'Shimane', '島根県' ),
			'JP33' => array( 'Okayama', 'Okayama', '岡山県' ),
			'JP34' => array( 'Hiroshima', 'Hiroshima', '広島県' ),
			'JP35' => array( 'Yamaguchi', 'Yamaguchi', '山口県' ),
			'JP36' => array( 'Tokushima', 'Tokushima', '徳島県' ),
			'JP37' => array( 'Kagawa', 'Kagawa', '香川県' ),
			'JP38' => array( 'Ehime', 'Ehime', '愛媛県' ),
			'JP39' => array( 'Kochi', 'Kochi', '高知県' ),
			'JP40' => array( 'Fukuoka', 'Fukuoka', '福岡県' ),
			'JP41' => array( 'Saga', 'Saga', '佐賀県' ),
			'JP42' => array( 'Nagasaki', 'Nagasaki', '長崎県' ),
			'JP43' => array( 'Kumamoto', 'Kumamoto', '熊本県' ),
			'JP44' => array( 'Oita', 'Oita', '大分県' ),
			'JP45' => array( 'Miyazaki', 'Miyazaki', '宮崎県' ),
			'JP46' => array( 'Kagoshima', 'Kagoshima', '鹿児島県' ),
			'JP47' => array( 'Okinawa', 'Okinawa', '沖縄県' ),
		),
		'MX' => array(
			'DF' => array( 'CDMX', 'Ciudad de México', null ),
			'JA' => array( 'Jal.', 'Jalisco', null ),
			'NL' => array( 'N.L.', 'Nuevo León', null ),
			'AG' => array( 'Ags.', 'Aguascalientes', null ),
			'BC' => array( 'B.C.', 'Baja California', null ),
			'BS' => array( 'B.C.S.', 'Baja California Sur', null ),
			'CM' => array( 'Camp.', 'Campeche', null ),
			'CS' => array( 'Chis.', 'Chiapas', null ),
			'CH' => array( 'Chih.', 'Chihuahua', null ),
			'CO' => array( 'Coah.', 'Coahuila de Zaragoza', null ),
			'CL' => array( 'Col.', 'Colima', null ),
			'DG' => array( 'Dgo.', 'Durango', null ),
			'GT' => array( 'Gto.', 'Guanajuato', null ),
			'GR' => array( 'Gro.', 'Guerrero', null ),
			'HG' => array( 'Hgo.', 'Hidalgo', null ),
			'MX' => array( 'Méx.', 'Estado de México', null ),
			'MI' => array( 'Mich.', 'Michoacán', null ),
			'MO' => array( 'Mor.', 'Morelos', null ),
			'NA' => array( 'Nay.', 'Nayarit', null ),
			'OA' => array( 'Oax.', 'Oaxaca', null ),
			'PU' => array( 'Pue.', 'Puebla', null ),
			'QT' => array( 'Qro.', 'Querétaro', null ),
			'QR' => array( 'Q.R.', 'Quintana Roo', null ),
			'SL' => array( 'S.L.P.', 'San Luis Potosí', null ),
			'SI' => array( 'Sin.', 'Sinaloa', null ),
			'SO' => array( 'Son.', 'Sonora', null ),
			'TB' => array( 'Tab.', 'Tabasco', null ),
			'TM' => array( 'Tamps.', 'Tamaulipas', null ),
			'TL' => array( 'Tlax.', 'Tlaxcala', null ),
			'VE' => array( 'Ver.', 'Veracruz', null ),
			'YU' => array( 'Yuc.', 'Yucatán', null ),
			'ZA' => array( 'Zac.', 'Zacatecas', null ),
		),
		'MY' => array(
			'JHR' => array( 'Johor', 'Johor', null ),
			'KDH' => array( 'Kedah', 'Kedah', null ),
			'KTN' => array( 'Kelantan', 'Kelantan', null ),
			'LBN' => array( 'Labuan', 'Labuan', null ),
			'MLK' => array( 'Melaka', 'Melaka', null ),
			'NSN' => array( 'Negeri Sembilan', 'Negeri Sembilan', null ),
			'PHG' => array( 'Pahang', 'Pahang', null ),
			'PNG' => array( 'Pulau Pinang', 'Pulau Pinang', null ),
			'PRK' => array( 'Perak', 'Perak', null ),
			'PLS' => array( 'Perlis', 'Perlis', null ),
			'SBH' => array( 'Sabah', 'Sabah', null ),
			'SWK' => array( 'Sarawak', 'Sarawak', null ),
			'SGR' => array( 'Selangor', 'Selangor', null ),
			'TRG' => array( 'Terengganu', 'Terengganu', null ),
			'PJY' => array( 'Putrajaya', 'Putrajaya', null ),
			'KUL' => array( 'Kuala Lumpur', 'Kuala Lumpur', null ),
		),
		'MZ' => array(
			'MZP'   => array( 'Cabo Delgado', 'Cabo Delgado', null ),
			'MZG'   => array( 'Gaza', 'Gaza', null ),
			'MZI'   => array( 'Inhambane', 'Inhambane', null ),
			'MZB'   => array( 'Manica', 'Manica', null ),
			'MZL'   => array( 'Maputo', 'Maputo', null ),
			'MZMPM' => array( 'Cidade de Maputo', 'Cidade de Maputo', null ),
			'MZN'   => array( 'Nampula', 'Nampula', null ),
			'MZA'   => array( 'Niassa', 'Niassa', null ),
			'MZS'   => array( 'Sofala', 'Sofala', null ),
			'MZT'   => array( 'Tete', 'Tete', null ),
			'MZQ'   => array( 'Zambezia', 'Zambezia', null ),
		),
		'NG' => array(
			'AB' => array( 'Abia', 'Abia', null ),
			'FC' => array( 'Federal Capital Territory', 'Federal Capital Territory', null ),
			'AD' => array( 'Adamawa', 'Adamawa', null ),
			'AK' => array( 'Akwa Ibom', 'Akwa Ibom', null ),
			'AN' => array( 'Anambra', 'Anambra', null ),
			'BA' => array( 'Bauchi', 'Bauchi', null ),
			'BY' => array( 'Bayelsa', 'Bayelsa', null ),
			'BE' => array( 'Benue', 'Benue', null ),
			'BO' => array( 'Borno', 'Borno', null ),
			'CR' => array( 'Cross River', 'Cross River', null ),
			'DE' => array( 'Delta', 'Delta', null ),
			'EB' => array( 'Ebonyi', 'Ebonyi', null ),
			'ED' => array( 'Edo', 'Edo', null ),
			'EK' => array( 'Ekiti', 'Ekiti', null ),
			'EN' => array( 'Enugu', 'Enugu', null ),
			'GO' => array( 'Gombe', 'Gombe', null ),
			'IM' => array( 'Imo', 'Imo', null ),
			'JI' => array( 'Jigawa', 'Jigawa', null ),
			'KD' => array( 'Kaduna', 'Kaduna', null ),
			'KN' => array( 'Kano', 'Kano', null ),
			'KT' => array( 'Katsina', 'Katsina', null ),
			'KE' => array( 'Kebbi', 'Kebbi', null ),
			'KO' => array( 'Kogi', 'Kogi', null ),
			'KW' => array( 'Kwara', 'Kwara', null ),
			'LA' => array( 'Lagos', 'Lagos', null ),
			'NA' => array( 'Nasarawa', 'Nasarawa', null ),
			'NI' => array( 'Niger', 'Niger', null ),
			'OG' => array( 'Ogun State', 'Ogun State', null ),
			'ON' => array( 'Ondo', 'Ondo', null ),
			'OS' => array( 'Osun', 'Osun', null ),
			'OY' => array( 'Oyo', 'Oyo', null ),
			'PL' => array( 'Plateau', 'Plateau', null ),
			'RI' => array( 'Rivers', 'Rivers', null ),
			'SO' => array( 'Sokoto', 'Sokoto', null ),
			'TA' => array( 'Taraba', 'Taraba', null ),
			'YO' => array( 'Yobe', 'Yobe', null ),
			'ZA' => array( 'Zamfara', 'Zamfara', null ),
		),
		'PE' => array(
			'CAL' => array( 'Callao', 'Callao', null ),
			'LMA' => array( 'Municipalidad Metropolitana de Lima', 'Municipalidad Metropolitana de Lima', null ),
			'AMA' => array( 'Amazonas', 'Amazonas', null ),
			'ANC' => array( 'Áncash', 'Áncash', null ),
			'APU' => array( 'Apurímac', 'Apurímac', null ),
			'ARE' => array( 'Arequipa', 'Arequipa', null ),
			'AYA' => array( 'Ayacucho', 'Ayacucho', null ),
			'CAJ' => array( 'Cajamarca', 'Cajamarca', null ),
			'CUS' => array( 'Cuzco', 'Cuzco', null ),
			'HUV' => array( 'Huancavelica', 'Huancavelica', null ),
			'HUC' => array( 'Huánuco', 'Huánuco', null ),
			'ICA' => array( 'Ica', 'Ica', null ),
			'JUN' => array( 'Junín', 'Junín', null ),
			'LAL' => array( 'La Libertad', 'La Libertad', null ),
			'LAM' => array( 'Lambayeque', 'Lambayeque', null ),
			'LIM' => array( 'Gobierno Regional de Lima', 'Gobierno Regional de Lima', null ),
			'LOR' => array( 'Loreto', 'Loreto', null ),
			'MDD' => array( 'Madre de Dios', 'Madre de Dios', null ),
			'MOQ' => array( 'Moquegua', 'Moquegua', null ),
			'PAS' => array( 'Pasco', 'Pasco', null ),
			'PIU' => array( 'Piura', 'Piura', null ),
			'PUN' => array( 'Puno', 'Puno', null ),
			'SAM' => array( 'San Martín', 'San Martín', null ),
			'TAC' => array( 'Tacna', 'Tacna', null ),
			'TUM' => array( 'Tumbes', 'Tumbes', null ),
			'UCA' => array( 'Ucayali', 'Ucayali', null ),
		),
		'PH' => array(
			'ABR' => array( 'Abra', 'Abra', null ),
			'AGN' => array( 'Agusan del Norte', 'Agusan del Norte', null ),
			'AGS' => array( 'Agusan del Sur', 'Agusan del Sur', null ),
			'AKL' => array( 'Aklan', 'Aklan', null ),
			'ALB' => array( 'Albay', 'Albay', null ),
			'ANT' => array( 'Antique', 'Antique', null ),
			'APA' => array( 'Apayao', 'Apayao', null ),
			'AUR' => array( 'Aurora', 'Aurora', null ),
			'BAS' => array( 'Basilan', 'Basilan', null ),
			'BAN' => array( 'Bataan', 'Bataan', null ),
			'BTN' => array( 'Batanes', 'Batanes', null ),
			'BTG' => array( 'Batangas', 'Batangas', null ),
			'BEN' => array( 'Benguet', 'Benguet', null ),
			'BIL' => array( 'Biliran', 'Biliran', null ),
			'BOH' => array( 'Bohol', 'Bohol', null ),
			'BUK' => array( 'Bukidnon', 'Bukidnon', null ),
			'BUL' => array( 'Bulacan', 'Bulacan', null ),
			'CAG' => array( 'Cagayan', 'Cagayan', null ),
			'CAN' => array( 'Camarines Norte', 'Camarines Norte', null ),
			'CAS' => array( 'Camarines Sur', 'Camarines Sur', null ),
			'CAM' => array( 'Camiguin', 'Camiguin', null ),
			'CAP' => array( 'Capiz', 'Capiz', null ),
			'CAT' => array( 'Catanduanes', 'Catanduanes', null ),
			'CAV' => array( 'Cavite', 'Cavite', null ),
			'CEB' => array( 'Cebu', 'Cebu', null ),
			'COM' => array( 'Compostela Valley', 'Compostela Valley', null ),
			'NCO' => array( 'Cotabato', 'Cotabato', null ),
			'DAV' => array( 'Davao del Norte', 'Davao del Norte', null ),
			'DAS' => array( 'Davao del Sur', 'Davao del Sur', null ),
			'DAC' => array( 'Davao Occidental', 'Davao Occidental', null ),
			'DAO' => array( 'Davao Oriental', 'Davao Oriental', null ),
			'DIN' => array( 'Dinagat Islands', 'Dinagat Islands', null ),
			'EAS' => array( 'Eastern Samar', 'Eastern Samar', null ),
			'GUI' => array( 'Guimaras', 'Guimaras', null ),
			'IFU' => array( 'Ifugao', 'Ifugao', null ),
			'ILN' => array( 'Ilocos Norte', 'Ilocos Norte', null ),
			'ILS' => array( 'Ilocos Sur', 'Ilocos Sur', null ),
			'ILI' => array( 'Iloilo', 'Iloilo', null ),
			'ISA' => array( 'Isabela', 'Isabela', null ),
			'KAL' => array( 'Kalinga', 'Kalinga', null ),
			'LUN' => array( 'La Union', 'La Union', null ),
			'LAG' => array( 'Laguna', 'Laguna', null ),
			'LAN' => array( 'Lanao del Norte', 'Lanao del Norte', null ),
			'LAS' => array( 'Lanao del Sur', 'Lanao del Sur', null ),
			'LEY' => array( 'Leyte', 'Leyte', null ),
			'MAG' => array( 'Maguindanao', 'Maguindanao', null ),
			'MAD' => array( 'Marinduque', 'Marinduque', null ),
			'MAS' => array( 'Masbate', 'Masbate', null ),
			'MSC' => array( 'Misamis Occidental', 'Misamis Occidental', null ),
			'MSR' => array( 'Misamis Oriental', 'Misamis Oriental', null ),
			'MOU' => array( 'Mountain Province', 'Mountain Province', null ),
			'NEC' => array( 'Negros Occidental', 'Negros Occidental', null ),
			'NER' => array( 'Negros Oriental', 'Negros Oriental', null ),
			'NSA' => array( 'Northern Samar', 'Northern Samar', null ),
			'NUE' => array( 'Nueva Ecija', 'Nueva Ecija', null ),
			'NUV' => array( 'Nueva Vizcaya', 'Nueva Vizcaya', null ),
			'MDC' => array( 'Mindoro Occidental', 'Mindoro Occidental', null ),
			'MDR' => array( 'Mindoro Oriental', 'Mindoro Oriental', null ),
			'PLW' => array( 'Palawan', 'Palawan', null ),
			'PAM' => array( 'Pampanga', 'Pampanga', null ),
			'PAN' => array( 'Pangasinan', 'Pangasinan', null ),
			'QUE' => array( 'Quezon Province', 'Quezon Province', null ),
			'QUI' => array( 'Quirino', 'Quirino', null ),
			'RIZ' => array( 'Rizal', 'Rizal', null ),
			'ROM' => array( 'Romblon', 'Romblon', null ),
			'WSA' => array( 'Samar', 'Samar', null ),
			'SAR' => array( 'Sarangani', 'Sarangani', null ),
			'SIQ' => array( 'Siquijor', 'Siquijor', null ),
			'SOR' => array( 'Sorsogon', 'Sorsogon', null ),
			'SCO' => array( 'South Cotabato', 'South Cotabato', null ),
			'SLE' => array( 'Southern Leyte', 'Southern Leyte', null ),
			'SUK' => array( 'Sultan Kudarat', 'Sultan Kudarat', null ),
			'SLU' => array( 'Sulu', 'Sulu', null ),
			'SUN' => array( 'Surigao del Norte', 'Surigao del Norte', null ),
			'SUR' => array( 'Surigao del Sur', 'Surigao del Sur', null ),
			'TAR' => array( 'Tarlac', 'Tarlac', null ),
			'TAW' => array( 'Tawi-Tawi', 'Tawi-Tawi', null ),
			'ZMB' => array( 'Zambales', 'Zambales', null ),
			'ZAN' => array( 'Zamboanga del Norte', 'Zamboanga del Norte', null ),
			'ZAS' => array( 'Zamboanga del Sur', 'Zamboanga del Sur', null ),
			'ZSI' => array( 'Zamboanga Sibuguey', 'Zamboanga Sibuguey', null ),
			'00'  => array( 'Metro Manila', 'Metro Manila', null ),
		),
		'TH' => array(
			'TH-37' => array( 'Amnat Charoen', 'Amnat Charoen', 'อำนาจเจริญ' ),
			'TH-15' => array( 'Ang Thong', 'Ang Thong', 'อ่างทอง' ),
			'TH-14' => array( 'Phra Nakhon Si Ayutthaya', 'Phra Nakhon Si Ayutthaya', 'พระนครศรีอยุธยา' ),
			'TH-10' => array( 'Bangkok', 'Bangkok', 'กรุงเทพมหานคร' ),
			'TH-38' => array( 'Bueng Kan', 'Bueng Kan', 'จังหวัด บึงกาฬ' ),
			'TH-31' => array( 'Buri Ram', 'Buri Ram', 'บุรีรัมย์' ),
			'TH-24' => array( 'Chachoengsao', 'Chachoengsao', 'ฉะเชิงเทรา' ),
			'TH-18' => array( 'Chai Nat', 'Chai Nat', 'ชัยนาท' ),
			'TH-36' => array( 'Chaiyaphum', 'Chaiyaphum', 'ชัยภูมิ' ),
			'TH-22' => array( 'Chanthaburi', 'Chanthaburi', 'จันทบุรี' ),
			'TH-50' => array( 'Chiang Mai', 'Chiang Mai', 'เชียงใหม่' ),
			'TH-57' => array( 'Chiang Rai', 'Chiang Rai', 'เชียงราย' ),
			'TH-20' => array( 'Chon Buri', 'Chon Buri', 'ชลบุรี' ),
			'TH-86' => array( 'Chumpon', 'Chumpon', 'ชุมพร' ),
			'TH-46' => array( 'Kalasin', 'Kalasin', 'กาฬสินธุ์' ),
			'TH-62' => array( 'Kamphaeng Phet', 'Kamphaeng Phet', 'กำแพงเพชร' ),
			'TH-71' => array( 'Kanchanaburi', 'Kanchanaburi', 'กาญจนบุรี' ),
			'TH-40' => array( 'Khon Kaen', 'Khon Kaen', 'ขอนแก่น' ),
			'TH-81' => array( 'Krabi', 'Krabi', 'กระบี่' ),
			'TH-52' => array( 'Lampang', 'Lampang', 'ลำปาง' ),
			'TH-51' => array( 'Lamphun', 'Lamphun', 'ลำพูน' ),
			'TH-42' => array( 'Loei', 'Loei', 'เลย' ),
			'TH-16' => array( 'Lop Buri', 'Lop Buri', 'ลพบุรี' ),
			'TH-58' => array( 'Mae Hong Son', 'Mae Hong Son', 'แม่ฮ่องสอน' ),
			'TH-44' => array( 'Maha Sarakham', 'Maha Sarakham', 'มหาสารคาม' ),
			'TH-49' => array( 'Mukdahan', 'Mukdahan', 'มุกดาหาร' ),
			'TH-26' => array( 'Nakhon Nayok', 'Nakhon Nayok', 'นครนายก' ),
			'TH-73' => array( 'Nakhon Pathom', 'Nakhon Pathom', 'นครปฐม' ),
			'TH-48' => array( 'Nakhon Phanom', 'Nakhon Phanom', 'นครพนม' ),
			'TH-30' => array( 'Nakhon Ratchasima', 'Nakhon Ratchasima', 'นครราชสีมา' ),
			'TH-60' => array( 'Nakhon Sawan', 'Nakhon Sawan', 'นครสวรรค์' ),
			'TH-80' => array( 'Nakhon Si Thammarat', 'Nakhon Si Thammarat', 'นครศรีธรรมราช' ),
			'TH-55' => array( 'Nan', 'Nan', 'น่าน' ),
			'TH-96' => array( 'Narathiwat', 'Narathiwat', 'นราธิวาส' ),
			'TH-39' => array( 'Nong Bua Lam Phu', 'Nong Bua Lam Phu', 'หนองบัวลำภู' ),
			'TH-43' => array( 'Nong Khai', 'Nong Khai', 'หนองคาย' ),
			'TH-12' => array( 'Nonthaburi', 'Nonthaburi', 'นนทบุรี' ),
			'TH-13' => array( 'Pathum Thani', 'Pathum Thani', 'ปทุมธานี' ),
			'TH-94' => array( 'Pattani', 'Pattani', 'ปัตตานี' ),
			'TH-82' => array( 'Phang Nga', 'Phang Nga', 'พังงา' ),
			'TH-93' => array( 'Phattalung', 'Phattalung', 'พัทลุง' ),
			'TH-56' => array( 'Phayao', 'Phayao', 'พะเยา' ),
			'TH-67' => array( 'Phetchabun', 'Phetchabun', 'เพชรบูรณ์' ),
			'TH-76' => array( 'Phetchaburi', 'Phetchaburi', 'เพชรบุรี' ),
			'TH-66' => array( 'Phichit', 'Phichit', 'พิจิตร' ),
			'TH-65' => array( 'Phitsanulok', 'Phitsanulok', 'พิษณุโลก' ),
			'TH-54' => array( 'Phrae', 'Phrae', 'แพร่' ),
			'TH-83' => array( 'Phuket', 'Phuket', 'ภูเก็ต' ),
			'TH-25' => array( 'Prachin Buri', 'Prachin Buri', 'ปราจีนบุรี' ),
			'TH-77' => array( 'Prachuap Khiri Khan', 'Prachuap Khiri Khan', 'ประจวบคีรีขันธ์' ),
			'TH-85' => array( 'Ranong', 'Ranong', 'ระนอง' ),
			'TH-70' => array( 'Ratchaburi', 'Ratchaburi', 'ราชบุรี' ),
			'TH-21' => array( 'Rayong', 'Rayong', 'ระยอง' ),
			'TH-45' => array( 'Roi Et', 'Roi Et', 'ร้อยเอ็ด' ),
			'TH-27' => array( 'Sa Kaeo', 'Sa Kaeo', 'สระแก้ว' ),
			'TH-47' => array( 'Sakon Nakhon', 'Sakon Nakhon', 'สกลนคร' ),
			'TH-11' => array( 'Samut Prakan', 'Samut Prakan', 'สมุทรปราการ' ),
			'TH-74' => array( 'Samut Sakhon', 'Samut Sakhon', 'สมุทรสาคร' ),
			'TH-75' => array( 'Samut Songkhram', 'Samut Songkhram', 'สมุทรสงคราม' ),
			'TH-19' => array( 'Saraburi', 'Saraburi', 'สระบุรี' ),
			'TH-91' => array( 'Satun', 'Satun', 'สตูล' ),
			'TH-17' => array( 'Sing Buri', 'Sing Buri', 'สิงห์บุรี' ),
			'TH-33' => array( 'Si Sa Ket', 'Si Sa Ket', 'ศรีสะเกษ' ),
			'TH-90' => array( 'Songkhla', 'Songkhla', 'สงขลา' ),
			'TH-64' => array( 'Sukhothai', 'Sukhothai', 'สุโขทัย' ),
			'TH-72' => array( 'Suphanburi', 'Suphanburi', 'สุพรรณบุรี' ),
			'TH-84' => array( 'Surat Thani', 'Surat Thani', 'สุราษฎร์ธานี' ),
			'TH-32' => array( 'Surin', 'Surin', 'สุรินทร์' ),
			'TH-63' => array( 'Tak', 'Tak', 'ตาก' ),
			'TH-92' => array( 'Trang', 'Trang', 'ตรัง' ),
			'TH-23' => array( 'Trat', 'Trat', 'ตราด' ),
			'TH-34' => array( 'Ubon Ratchathani', 'Ubon Ratchathani', 'อุบลราชธานี' ),
			'TH-41' => array( 'Udon Thani', 'Udon Thani', 'อุดรธานี' ),
			'TH-61' => array( 'Uthai Thani', 'Uthai Thani', 'อุทัยธานี' ),
			'TH-53' => array( 'Uttaradit', 'Uttaradit', 'อุตรดิตถ์' ),
			'TH-95' => array( 'Yala', 'Yala', 'ยะลา' ),
			'TH-35' => array( 'Yasothon', 'Yasothon', 'ยโสธร' ),
		),
		'TR' => array(
			'TR01' => array( 'Adana', 'Adana', null ),
			'TR02' => array( 'Adıyaman', 'Adıyaman', null ),
			'TR03' => array( 'Afyon', 'Afyon', null ),
			'TR04' => array( 'Ağrı', 'Ağrı', null ),
			'TR05' => array( 'Amasya', 'Amasya', null ),
			'TR06' => array( 'Ankara', 'Ankara', null ),
			'TR07' => array( 'Antalya', 'Antalya', null ),
			'TR08' => array( 'Artvin', 'Artvin', null ),
			'TR09' => array( 'Aydın', 'Aydın', null ),
			'TR10' => array( 'Balıkesir', 'Balıkesir', null ),
			'TR11' => array( 'Bilecik', 'Bilecik', null ),
			'TR12' => array( 'Bingöl', 'Bingöl', null ),
			'TR13' => array( 'Bitlis', 'Bitlis', null ),
			'TR14' => array( 'Bolu', 'Bolu', null ),
			'TR15' => array( 'Burdur', 'Burdur', null ),
			'TR16' => array( 'Bursa', 'Bursa', null ),
			'TR17' => array( 'Çanakkale', 'Çanakkale', null ),
			'TR18' => array( 'Çankırı', 'Çankırı', null ),
			'TR19' => array( 'Çorum', 'Çorum', null ),
			'TR20' => array( 'Denizli', 'Denizli', null ),
			'TR21' => array( 'Diyarbakır', 'Diyarbakır', null ),
			'TR22' => array( 'Edirne', 'Edirne', null ),
			'TR23' => array( 'Elazığ', 'Elazığ', null ),
			'TR24' => array( 'Erzincan', 'Erzincan', null ),
			'TR25' => array( 'Erzurum', 'Erzurum', null ),
			'TR26' => array( 'Eskişehir', 'Eskişehir', null ),
			'TR27' => array( 'Gaziantep', 'Gaziantep', null ),
			'TR28' => array( 'Giresun', 'Giresun', null ),
			'TR29' => array( 'Gümüşhane', 'Gümüşhane', null ),
			'TR30' => array( 'Hakkari', 'Hakkari', null ),
			'TR31' => array( 'Hatay', 'Hatay', null ),
			'TR32' => array( 'Isparta', 'Isparta', null ),
			'TR33' => array( 'Mersin', 'Mersin', null ),
			'TR34' => array( 'İstanbul', 'İstanbul', null ),
			'TR35' => array( 'İzmir', 'İzmir', null ),
			'TR36' => array( 'Kars', 'Kars', null ),
			'TR37' => array( 'Kastamonu', 'Kastamonu', null ),
			'TR38' => array( 'Kayseri', 'Kayseri', null ),
			'TR39' => array( 'Kırklareli', 'Kırklareli', null ),
			'TR40' => array( 'Kırşehir', 'Kırşehir', null ),
			'TR41' => array( 'Kocaeli', 'Kocaeli', null ),
			'TR42' => array( 'Konya', 'Konya', null ),
			'TR43' => array( 'Kütahya', 'Kütahya', null ),
			'TR44' => array( 'Malatya', 'Malatya', null ),
			'TR45' => array( 'Manisa', 'Manisa', null ),
			'TR46' => array( 'Kahramanmaraş', 'Kahramanmaraş', null ),
			'TR47' => array( 'Mardin', 'Mardin', null ),
			'TR48' => array( 'Muğla', 'Muğla', null ),
			'TR49' => array( 'Muş', 'Muş', null ),
			'TR50' => array( 'Nevşehir', 'Nevşehir', null ),
			'TR51' => array( 'Niğde', 'Niğde', null ),
			'TR52' => array( 'Ordu', 'Ordu', null ),
			'TR53' => array( 'Rize', 'Rize', null ),
			'TR54' => array( 'Sakarya', 'Sakarya', null ),
			'TR55' => array( 'Samsun', 'Samsun', null ),
			'TR56' => array( 'Siirt', 'Siirt', null ),
			'TR57' => array( 'Sinop', 'Sinop', null ),
			'TR58' => array( 'Sivas', 'Sivas', null ),
			'TR59' => array( 'Tekirdağ', 'Tekirdağ', null ),
			'TR60' => array( 'Tokat', 'Tokat', null ),
			'TR61' => array( 'Trabzon', 'Trabzon', null ),
			'TR62' => array( 'Tunceli', 'Tunceli', null ),
			'TR63' => array( 'Şanlıurfa', 'Şanlıurfa', null ),
			'TR64' => array( 'Uşak', 'Uşak', null ),
			'TR65' => array( 'Van', 'Van', null ),
			'TR66' => array( 'Yozgat', 'Yozgat', null ),
			'TR67' => array( 'Zonguldak', 'Zonguldak', null ),
			'TR68' => array( 'Aksaray', 'Aksaray', null ),
			'TR69' => array( 'Bayburt', 'Bayburt', null ),
			'TR70' => array( 'Karaman', 'Karaman', null ),
			'TR71' => array( 'Kırıkkale', 'Kırıkkale', null ),
			'TR72' => array( 'Batman', 'Batman', null ),
			'TR73' => array( 'Şırnak', 'Şırnak', null ),
			'TR74' => array( 'Bartın', 'Bartın', null ),
			'TR75' => array( 'Ardahan', 'Ardahan', null ),
			'TR76' => array( 'Iğdır', 'Iğdır', null ),
			'TR77' => array( 'Yalova', 'Yalova', null ),
			'TR78' => array( 'Karabük', 'Karabük', null ),
			'TR79' => array( 'Kilis', 'Kilis', null ),
			'TR80' => array( 'Osmaniye', 'Osmaniye', null ),
			'TR81' => array( 'Düzce', 'Düzce', null ),
		),
		'US' => array(
			'AL' => array( 'AL', 'Alabama', null ),
			'AK' => array( 'AK', 'Alaska', null ),
			'AZ' => array( 'AZ', 'Arizona', null ),
			'AR' => array( 'AR', 'Arkansas', null ),
			'CA' => array( 'CA', 'California', null ),
			'CO' => array( 'CO', 'Colorado', null ),
			'CT' => array( 'CT', 'Connecticut', null ),
			'DE' => array( 'DE', 'Delaware', null ),
			'DC' => array( 'DC', 'District of Columbia', null ),
			'FL' => array( 'FL', 'Florida', null ),
			'GA' => array( 'GA', 'Georgia', null ),
			'HI' => array( 'HI', 'Hawaii', null ),
			'ID' => array( 'ID', 'Idaho', null ),
			'IL' => array( 'IL', 'Illinois', null ),
			'IN' => array( 'IN', 'Indiana', null ),
			'IA' => array( 'IA', 'Iowa', null ),
			'KS' => array( 'KS', 'Kansas', null ),
			'KY' => array( 'KY', 'Kentucky', null ),
			'LA' => array( 'LA', 'Louisiana', null ),
			'ME' => array( 'ME', 'Maine', null ),
			'MD' => array( 'MD', 'Maryland', null ),
			'MA' => array( 'MA', 'Massachusetts', null ),
			'MI' => array( 'MI', 'Michigan', null ),
			'MN' => array( 'MN', 'Minnesota', null ),
			'MS' => array( 'MS', 'Mississippi', null ),
			'MO' => array( 'MO', 'Missouri', null ),
			'MT' => array( 'MT', 'Montana', null ),
			'NE' => array( 'NE', 'Nebraska', null ),
			'NV' => array( 'NV', 'Nevada', null ),
			'NH' => array( 'NH', 'New Hampshire', null ),
			'NJ' => array( 'NJ', 'New Jersey', null ),
			'NM' => array( 'NM', 'New Mexico', null ),
			'NY' => array( 'NY', 'New York', null ),
			'NC' => array( 'NC', 'North Carolina', null ),
			'ND' => array( 'ND', 'North Dakota', null ),
			'OH' => array( 'OH', 'Ohio', null ),
			'OK' => array( 'OK', 'Oklahoma', null ),
			'OR' => array( 'OR', 'Oregon', null ),
			'PA' => array( 'PA', 'Pennsylvania', null ),
			'RI' => array( 'RI', 'Rhode Island', null ),
			'SC' => array( 'SC', 'South Carolina', null ),
			'SD' => array( 'SD', 'South Dakota', null ),
			'TN' => array( 'TN', 'Tennessee', null ),
			'TX' => array( 'TX', 'Texas', null ),
			'UT' => array( 'UT', 'Utah', null ),
			'VT' => array( 'VT', 'Vermont', null ),
			'VA' => array( 'VA', 'Virginia', null ),
			'WA' => array( 'WA', 'Washington', null ),
			'WV' => array( 'WV', 'West Virginia', null ),
			'WI' => array( 'WI', 'Wisconsin', null ),
			'WY' => array( 'WY', 'Wyoming', null ),
			'AA' => array( 'AA', 'Armed Forces (AA)', null ),
			'AE' => array( 'AE', 'Armed Forces (AE)', null ),
			'AP' => array( 'AP', 'Armed Forces (AP)', null ),
		),
	);

	/**
	 * Normalized postcodes allowed to bypass validation for the active tokenized update-customer request.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $tokenized_postcode_validation_allowlist = array();

	/**
	 * Normalize tokenized Store API address values before Store API validation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 */
	public function normalize_tokenized_cart_store_api_addresses( WP_REST_Request $request ): void {
		if ( ! $this->should_normalize_tokenized_cart_address( $request ) ) {
			return;
		}

		$is_update_customer_route = '/wc/store/v1/cart/update-customer' === $request->get_route();
		if ( $is_update_customer_route ) {
			$this->tokenized_postcode_validation_allowlist = array();
			if ( false === has_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ) ) ) {
				add_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ), 10, 3 );
			}
			if ( false === has_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ) ) ) {
				add_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ), 10, 3 );
			}
		}

		foreach ( array( 'shipping_address', 'billing_address' ) as $address_key ) {
			$address = $request->get_param( $address_key );
			if ( is_array( $address ) ) {
				$address = $this->normalize_ece_address( $address, $is_update_customer_route );
				if ( $is_update_customer_route ) {
					$this->add_tokenized_postcode_validation_exception( $address );
				}
				$request->set_param( $address_key, $address );
			}
		}
	}

	/**
	 * Allow redacted postcodes that were padded for tokenized express-checkout shipping lookups.
	 *
	 * @param bool   $valid    Whether the postcode is valid.
	 * @param string $postcode Postcode value.
	 * @param string $country  Country code.
	 * @return bool
	 */
	public function maybe_skip_postcode_validation( $valid, $postcode, $country ): bool {
		$country = strtoupper( (string) $country );
		if ( ! in_array( $country, array( 'GB', 'CA' ), true ) ) {
			return (bool) $valid;
		}

		$postcode = $this->normalize_postcode_for_validation_allowlist( (string) $postcode );

		return isset( $this->tokenized_postcode_validation_allowlist[ $country ][ $postcode ] ) ? true : (bool) $valid;
	}

	/**
	 * Clear tokenized postcode validation state after the current Store API dispatch.
	 *
	 * @internal
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function clear_tokenized_postcode_validation( $response, $server = null, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- rest_post_dispatch passes three arguments.
		remove_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ), 10 );
		remove_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ), 10 );
		$this->tokenized_postcode_validation_allowlist = array();

		return $response;
	}

	/**
	 * Tell whether tokenized Store API address values may be normalized.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return bool
	 */
	private function should_normalize_tokenized_cart_address( WP_REST_Request $request ): bool {
		if ( 'true' !== $request->get_header( self::TOKENIZED_CART_HEADER ) ) {
			return false;
		}

		$nonce = (string) $request->get_header( self::TOKENIZED_CART_NONCE_HEADER );

		return '' !== $nonce && (bool) \wp_verify_nonce( $nonce, self::TOKENIZED_CART_NONCE_ACTION );
	}

	/**
	 * Normalize express-checkout address fields that Stripe wallet payloads can redact or reshape.
	 *
	 * @param array<string,mixed> $address            Address values.
	 * @param bool                $normalize_postcode Whether to normalize redacted postcode values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address( array $address, bool $normalize_postcode ): array {
		$address = $this->normalize_ece_address_state( $address );
		$address = $this->normalize_ece_address_lines( $address );

		return $normalize_postcode ? $this->normalize_ece_address_postcode( $address ) : $address;
	}

	/**
	 * Normalize address state values to WooCommerce state keys when possible.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_state( array $address ): array {
		$country = $this->sanitize_address_value( $address['country'] ?? '' );
		if ( '' === $country ) {
			return $address;
		}

		if ( 'HK' === $country ) {
			foreach ( array( $address['state'] ?? '', $address['postcode'] ?? '', $address['city'] ?? '' ) as $candidate ) {
				$region = $this->get_hong_kong_region_for_candidate( $this->sanitize_address_value( $candidate ) );
				if ( '' !== $region ) {
					$address['state'] = $region;
					break;
				}
			}
		}

		$state = $this->sanitize_address_value( $address['state'] ?? '' );
		if ( '' !== $state ) {
			$address['state'] = $this->get_normalized_state( $state, $country );
		}

		return $address;
	}

	/**
	 * Shift populated address lines so address_1 is present when any line is.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_lines( array $address ): array {
		$lines = array_values(
			array_filter(
				array(
					trim( $this->sanitize_address_value( $address['address_1'] ?? '' ) ),
					trim( $this->sanitize_address_value( $address['address_2'] ?? '' ) ),
				),
				static function ( string $line ): bool {
					return '' !== $line;
				}
			)
		);

		if ( empty( $lines ) ) {
			return $address;
		}

		$address['address_1'] = $lines[0];
		$address['address_2'] = $lines[1] ?? '';

		return $address;
	}

	/**
	 * Normalize redacted postcode values used for tokenized shipping-rate lookup.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_postcode( array $address ): array {
		$country  = $this->sanitize_address_value( $address['country'] ?? '' );
		$postcode = $this->sanitize_address_value( $address['postcode'] ?? '' );
		if ( '' !== $country && '' !== $postcode ) {
			$address['postcode'] = $this->get_normalized_postal_code( $postcode, $country );
		}

		return $address;
	}

	/**
	 * Normalize redacted postal-code values for countries where wallets can send partial values.
	 *
	 * @param string $postcode Postcode value.
	 * @param string $country  Country code.
	 * @return string
	 */
	private function get_normalized_postal_code( string $postcode, string $country ): string {
		$country = strtoupper( $country );

		if ( 'GB' === $country ) {
			$cleaned_postcode = preg_replace( '/[^A-Za-z0-9]/', '', $postcode );
			$cleaned_postcode = substr( is_string( $cleaned_postcode ) ? $cleaned_postcode : '', 0, 7 );

			return strlen( $cleaned_postcode ) >= 5 ? $cleaned_postcode : $cleaned_postcode . '000';
		}

		if ( 'CA' === $country ) {
			$cleaned_postcode = preg_replace( '/\s+/', '', $postcode );

			return str_pad( is_string( $cleaned_postcode ) ? $cleaned_postcode : '', 6, '0' );
		}

		return $postcode;
	}

	/**
	 * Add a normalized postcode to the request-scoped validation allowlist.
	 *
	 * @param array<string,mixed> $address Address values.
	 */
	private function add_tokenized_postcode_validation_exception( array $address ): void {
		$country  = strtoupper( $this->sanitize_address_value( $address['country'] ?? '' ) );
		$postcode = $this->normalize_postcode_for_validation_allowlist( $this->sanitize_address_value( $address['postcode'] ?? '' ) );
		if ( '' === $postcode || ! in_array( $country, array( 'GB', 'CA' ), true ) ) {
			return;
		}

		$this->tokenized_postcode_validation_allowlist[ $country ][ $postcode ] = true;
	}

	/**
	 * Normalize postcode strings for request-scoped validation comparisons.
	 *
	 * @param string $postcode Postcode value.
	 * @return string
	 */
	private function normalize_postcode_for_validation_allowlist( string $postcode ): string {
		$postcode = preg_replace( '/[^A-Za-z0-9]/', '', strtoupper( $postcode ) );

		return is_string( $postcode ) ? $postcode : '';
	}

	/**
	 * Normalize a wallet state value to a WooCommerce state key.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_normalized_state( string $state, string $country ): string {
		if ( '' === $state || $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		// An exact match comes first: the WooCommerce pass below also takes a state whose name sits inside the value,
		// so on its own it reads "West Virginia" as Virginia.
		$state = $this->get_normalized_state_from_express_checkout_states( $state, $country );
		if ( $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		return $this->get_normalized_state_from_wc_states( $state, $country );
	}

	/**
	 * Tell whether a state value is already a WooCommerce state key.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return bool
	 */
	private function is_normalized_state( string $state, string $country ): bool {
		$wc_states = WC()->countries->get_states( $country );

		return is_array( $wc_states ) && array_key_exists( $state, $wc_states );
	}

	/**
	 * Match a state value exactly against the wallet codes, names and local names of the country's states.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return string The WooCommerce state key, or the value as given when nothing matches.
	 */
	private function get_normalized_state_from_express_checkout_states( string $state, string $country ): string {
		$normalized_state = $this->normalize_address_candidate( $state );

		foreach ( self::EXPRESS_CHECKOUT_STATES[ $country ] ?? array() as $wc_state_key => $wallet_values ) {
			foreach ( $wallet_values as $wallet_value ) {
				if ( null !== $wallet_value && $normalized_state === $this->normalize_address_candidate( $wallet_value ) ) {
					return $wc_state_key;
				}
			}
		}

		return $state;
	}

	/**
	 * Match a state value against WooCommerce's translated state labels.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_normalized_state_from_wc_states( string $state, string $country ): string {
		$wc_states        = WC()->countries->get_states( $country );
		$normalized_state = $this->normalize_address_candidate( $state );
		if ( ! is_array( $wc_states ) || '' === $normalized_state ) {
			return $state;
		}

		$normalized_state_values = array();
		foreach ( $wc_states as $wc_state_abbr => $wc_state_value ) {
			$normalized_state_values[ (string) $wc_state_abbr ] = $this->normalize_address_candidate( (string) $wc_state_value );
		}

		// A state whose key or whole name matches wins over one whose name only sits inside the value: with accents
		// folded, "Zürich" contains Uri, and the full Dādra and Nagar Haveli and Damān and Diu contains Daman and Diu.
		foreach ( $normalized_state_values as $wc_state_abbr => $normalized_state_value ) {
			if ( $normalized_state === $this->normalize_address_candidate( (string) $wc_state_abbr ) || $normalized_state === $normalized_state_value ) {
				return (string) $wc_state_abbr;
			}
		}

		foreach ( $normalized_state_values as $wc_state_abbr => $normalized_state_value ) {
			if ( '' !== $normalized_state_value && false !== strpos( $normalized_state, $normalized_state_value ) ) {
				return (string) $wc_state_abbr;
			}
		}

		return $state;
	}

	/**
	 * Resolve a Hong Kong region from wallet state, postcode, or city values.
	 *
	 * @param string $candidate Address candidate value.
	 * @return string
	 */
	private function get_hong_kong_region_for_candidate( string $candidate ): string {
		$normalized_candidate = $this->normalize_address_candidate( $candidate );
		if ( 'hongkong' === $normalized_candidate ) {
			$normalized_candidate = 'hong kong';
		}

		foreach ( self::HONG_KONG_REGION_CANDIDATES as $region => $region_candidates ) {
			foreach ( $region_candidates as $region_candidate ) {
				$normalized_region_candidate = $this->normalize_address_candidate( $region_candidate );
				if ( $normalized_candidate === $normalized_region_candidate ) {
					return $region;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize an address value for loose matching.
	 *
	 * @param string $value Address value.
	 * @return string
	 */
	private function normalize_address_candidate( string $value ): string {
		$value = remove_accents( $value );
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sanitize a scalar address value.
	 *
	 * @param mixed $value Address value.
	 * @return string
	 */
	private function sanitize_address_value( $value ): string {
		$sanitized_value = is_scalar( $value ) ? wc_clean( (string) $value ) : '';

		return is_scalar( $sanitized_value ) ? (string) $sanitized_value : '';
	}
}
