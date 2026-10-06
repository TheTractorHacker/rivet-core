<?php

declare(strict_types=1);

namespace RivetCore\Ui;

/**
 * Curated catalog of Font Awesome 5/6 FREE SOLID icons for the editions' visual icon pickers (saved ticket views,
 * tags, custom links, service catalog items). Core owns the data and the validation; the editions render the picker.
 *
 * Two different questions, deliberately separate:
 *  - {@see has()} / catalogued: is the class one of the curated entries? (picker highlighting, "custom" badge)
 *  - {@see normalize()}: is the string a safe, canonical `fa-xxx` class? ANY syntactically valid class is accepted
 *    even when it is not curated, because admins already stored free-text classes before the picker existed.
 *
 * Every curated class exists in the Font Awesome Free 5.15 solid set and (under the same name) in 6.x. Entries
 * only ever get added; bump {@see VERSION} whenever the list changes so editions can cache-bust the embedded JSON.
 *
 * @api
 */
final class IconCatalog
{
    /** Bumped whenever entries are added, removed or renamed. */
    public const VERSION = 1;

    /** Longest stored value (matches the editions' varchar(50)). */
    public const MAX_LENGTH = 50;

    private const PATTERN = '/^fa-[a-z0-9]+(-[a-z0-9]+)*\z/D';

    /** Style/prefix tokens that may precede the icon name and are stripped. */
    private const STYLE_TOKENS = ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'];

    private const CATEGORIES = [
        'general' => 'General',
        'tickets' => 'Tickets & support',
        'status' => 'Status & alerts',
        'people' => 'People & teams',
        'devices' => 'Devices & network',
        'security' => 'Security',
        'files' => 'Files & documents',
        'communication' => 'Communication',
        'money' => 'Money & billing',
        'time' => 'Time & calendar',
        'places' => 'Places & transport',
        'tools' => 'Tools & settings',
        'arrows' => 'Arrows & shapes',
        'nature' => 'Nature & misc',
    ];

    /**
     * Entry format: "name|Label|keyword,keyword". The class is "fa-" . name.
     *
     * @var array<string,list<string>>
     */
    private const DATA = [
        'general' => [
            'star|Star|favorite,important,rating', 'heart|Heart|love,favorite,like', 'home|Home|house,start,main',
            'flag|Flag|marker,report,milestone', 'bookmark|Bookmark|save,saved,favorite', 'tag|Tag|label,category',
            'tags|Tags|labels,categories', 'thumbtack|Pin|pinned,sticky', 'search|Search|find,magnifier,lookup',
            'filter|Filter|funnel,narrow,view', 'th-large|Grid|tiles,dashboard,blocks', 'th-list|List grid|rows,table',
            'list|List|items,menu', 'list-ul|Bulleted list|items,bullets', 'list-ol|Numbered list|ordered,steps',
            'bars|Menu|hamburger,navigation', 'layer-group|Layers|stack,group', 'cube|Cube|package,object,box',
            'cubes|Cubes|packages,objects,stack', 'puzzle-piece|Puzzle piece|plugin,addon,integration',
            'lightbulb|Idea|bulb,tip,suggestion', 'gift|Gift|present,reward', 'trophy|Trophy|win,award,achievement',
            'award|Award|badge,prize', 'medal|Medal|prize,rank', 'crown|Crown|vip,premium,king', 'gem|Gem|diamond,premium',
            'rocket|Rocket|launch,fast,project', 'magic|Magic|wand,automatic,automation', 'bolt|Bolt|lightning,fast,power',
            'fire|Fire|urgent,hot,critical', 'fire-alt|Flame|urgent,hot,burning', 'eye|Eye|view,watch,visible',
            'eye-slash|Eye slash|hidden,invisible', 'thumbs-up|Thumbs up|like,approve,good',
            'thumbs-down|Thumbs down|dislike,reject,bad', 'smile|Smile|happy,satisfied', 'frown|Frown|unhappy,sad',
            'meh|Meh|neutral', 'bullseye|Target|goal,aim', 'crosshairs|Crosshairs|target,locate',
            'th|Grid small|tiles', 'columns|Columns|layout,board,kanban', 'table|Table|spreadsheet,grid,data',
            'object-group|Group|objects,combine', 'clone|Clone|duplicate,copy', 'hashtag|Hashtag|number,topic',
            'at|At sign|mention,email', 'infinity|Infinity|unlimited,forever', 'asterisk|Asterisk|required,star',
            'bell|Bell|notification,alert,reminder', 'bell-slash|Bell off|muted,silence',
        ],
        'tickets' => [
            'ticket-alt|Ticket|support,request,issue', 'life-ring|Life ring|help,support,rescue',
            'headset|Headset|support,helpdesk,agent', 'headphones|Headphones|support,audio',
            'question-circle|Question|help,unknown,faq', 'question|Question mark|help,unknown',
            'info-circle|Information|info,details,note', 'bug|Bug|defect,error,problem',
            'clipboard-list|Task list|tasks,checklist,todo', 'clipboard-check|Checked list|done,approved,checklist',
            'clipboard|Clipboard|paste,notes', 'tasks|Tasks|todo,checklist,progress', 'inbox|Inbox|incoming,queue,mailbox',
            'comment-dots|Conversation|chat,reply,discussion', 'comments|Comments|chat,discussion',
            'concierge-bell|Service bell|service,desk,request', 'hands-helping|Helping hands|assist,support,volunteer',
            'user-clock|Waiting on user|pending,waiting', 'hourglass-half|Waiting|pending,in progress',
            'sticky-note|Note|memo,internal', 'paperclip|Attachment|attach,file', 'link|Link|url,chain,related',
            'sitemap|Sitemap|hierarchy,tree,structure', 'project-diagram|Project diagram|workflow,flow,relations',
            'stethoscope|Diagnose|health,check,triage', 'first-aid|First aid|emergency,fix,help',
            'ambulance|Ambulance|emergency,urgent', 'wrench|Wrench|fix,repair,maintenance', 'toolbox|Toolbox|tools,fix,kit',
            'box-open|Open box|unpack,deliver,release', 'boxes|Boxes|inventory,stock,packages',
            'dolly|Dolly|move,hardware,delivery', 'shipping-fast|Fast shipping|delivery,expedite',
            'user-tag|Assigned|assignee,owner', 'user-edit|Edited by|assign,edit user', 'redo|Reopen|retry,repeat',
            'balance-scale|Balance|policy,fair,legal', 'sort-amount-down|Priority sort|order,rank,sort',
            'flag-checkered|Finish line|done,complete,finish', 'book-reader|Reader|knowledge,read',
            'poll|Survey|poll,feedback,results', 'ad|Ad|promotion,banner',
        ],
        'status' => [
            'check|Check|ok,done,success', 'check-circle|Check circle|ok,done,resolved,success',
            'check-double|Double check|verified,read,approved', 'check-square|Check box|done,task',
            'times|Close|cancel,x,remove', 'times-circle|Error circle|failed,error,close',
            'ban|Ban|blocked,forbidden,denied', 'exclamation|Exclamation|alert,warning,important',
            'exclamation-circle|Alert circle|warning,attention,important',
            'exclamation-triangle|Warning|alert,caution,danger,urgent', 'skull-crossbones|Danger|deadly,critical,fatal',
            'radiation|Radiation|hazard,danger', 'biohazard|Biohazard|hazard,virus,danger',
            'fire-extinguisher|Fire extinguisher|emergency,safety', 'bomb|Bomb|critical,explosive,outage',
            'burn|Burn|hot,urgent', 'heartbeat|Heartbeat|health,monitor,pulse', 'traffic-light|Traffic light|status,rag,signal',
            'circle|Circle|dot,status', 'dot-circle|Dot circle|selected,radio', 'adjust|Half circle|contrast,partial',
            'toggle-on|Toggle on|enabled,active,switch', 'toggle-off|Toggle off|disabled,inactive,switch',
            'play|Play|start,run,resume', 'pause|Pause|hold,on hold,wait', 'stop|Stop|halt,end', 'play-circle|Play circle|start,run',
            'pause-circle|Pause circle|hold,paused', 'stop-circle|Stop circle|halt,stopped', 'spinner|Spinner|loading,progress',
            'sync-alt|Sync|refresh,update,loop', 'undo-alt|Undo|revert,rollback', 'redo-alt|Redo|repeat,reload',
            'hourglass-start|Hourglass start|begin,new', 'hourglass-end|Hourglass end|expired,timeout',
            'battery-half|Battery half|power,level', 'battery-empty|Battery empty|low,dead', 'thermometer-half|Thermometer|temperature,health',
            'tachometer-alt|Gauge|speed,performance,dashboard', 'chart-line|Line chart|trend,graph,stats',
            'chart-bar|Bar chart|stats,graph,report', 'chart-pie|Pie chart|stats,breakdown,report',
            'chart-area|Area chart|stats,trend', 'signal|Signal|strength,connectivity', 'ghost|Ghost|stale,abandoned,spooky',
            'dizzy|Dizzy|confused,broken', 'angry|Angry|frustrated,escalation', 'sad-tear|Sad|unhappy,upset',
            'grin|Grin|happy,great', 'laugh|Laugh|happy,funny',
        ],
        'people' => [
            'user|User|person,account,profile', 'user-alt|User alt|person,profile', 'user-circle|User circle|avatar,profile',
            'users|Users|team,group,people', 'user-friends|Friends|team,pair,contacts', 'user-plus|Add user|new,invite',
            'user-minus|Remove user|delete,offboard', 'user-check|Verified user|approved,active', 'user-times|Remove user x|banned,removed',
            'user-cog|User settings|admin,configure', 'users-cog|Team settings|admin,groups',
            'user-tie|Manager|business,executive,boss', 'user-shield|Protected user|admin,security', 'user-lock|Locked user|restricted,access',
            'user-secret|Secret user|anonymous,private,spy', 'user-ninja|Ninja|expert,stealth', 'user-md|Doctor|medical,health',
            'user-nurse|Nurse|medical,health', 'user-graduate|Graduate|training,student,learning',
            'user-astronaut|Astronaut|space,explorer', 'user-injured|Injured user|incident,hurt', 'user-slash|User slash|disabled,blocked',
            'id-badge|ID badge|employee,identity', 'id-card|ID card|identity,employee,license', 'address-book|Address book|contacts,directory',
            'address-card|Contact card|contact,vcard', 'handshake|Handshake|partner,deal,agreement', 'hands|Hands|support,welcome',
            'hand-holding-heart|Care|support,donation', 'people-carry|Carrying|moving,team', 'child|Child|kid,junior',
            'baby|Baby|new,infant', 'male|Male|man', 'female|Female|woman', 'chalkboard-teacher|Teacher|training,trainer,lesson',
            'graduation-cap|Graduation|training,education,course', 'portrait|Portrait|person,photo,avatar',
            'street-view|Street view|person,location', 'walking|Walking|person,onsite', 'hard-hat|Hard hat|construction,onsite,field',
            'tshirt|Shirt|uniform,clothing', 'smile-beam|Smile beam|happy,satisfied',
        ],
        'devices' => [
            'desktop|Desktop|computer,pc,workstation', 'laptop|Laptop|notebook,computer', 'laptop-code|Laptop code|developer,programming',
            'tablet-alt|Tablet|ipad,device', 'mobile-alt|Mobile|phone,smartphone,device', 'server|Server|host,datacenter,rack',
            'database|Database|data,storage,sql', 'hdd|Hard drive|disk,storage', 'sd-card|SD card|storage,memory',
            'sim-card|SIM card|cellular,mobile', 'memory|Memory|ram,chip', 'microchip|Chip|cpu,processor,hardware',
            'network-wired|Network|lan,wired,topology', 'ethernet|Ethernet|network,cable,port', 'wifi|Wi-Fi|wireless,network',
            'broadcast-tower|Tower|antenna,wireless,broadcast', 'satellite-dish|Satellite dish|antenna,wan',
            'satellite|Satellite|space,wan', 'plug|Plug|power,connect,integration', 'charging-station|Charging station|power,battery',
            'print|Printer|printing,paper', 'tv|Television|monitor,display,screen', 'keyboard|Keyboard|input,typing',
            'mouse|Mouse|input,pointer', 'mouse-pointer|Pointer|cursor,click', 'headphones-alt|Headphones alt|audio,headset',
            'microphone|Microphone|audio,voice', 'microphone-slash|Microphone muted|mute,audio', 'camera|Camera|photo,picture',
            'video|Video camera|webcam,recording', 'phone|Phone|call,voip,telephone', 'phone-alt|Phone alt|call,voip',
            'fax|Fax|facsimile', 'tty|Teletype|phone,accessibility', 'cloud|Cloud|saas,hosting,online',
            'cloud-download-alt|Cloud download|download,sync', 'cloud-upload-alt|Cloud upload|upload,backup',
            'terminal|Terminal|console,shell,command', 'code|Code|programming,script,dev', 'code-branch|Branch|git,version,fork',
            'qrcode|QR code|scan,code', 'barcode|Barcode|scan,asset,sku', 'gamepad|Gamepad|game,controller',
            'robot|Robot|bot,automation,ai', 'power-off|Power|shutdown,off,restart', 'window-maximize|Window|app,screen',
            'vr-cardboard|VR headset|virtual,goggles', 'compact-disc|Disc|cd,dvd,media', 'solar-panel|Solar panel|power,energy',
            'dice-d6|Die|random,hardware',
        ],
        'security' => [
            'lock|Lock|secure,private,password', 'unlock|Unlock|open,access', 'unlock-alt|Unlock alt|open,access',
            'key|Key|password,credential,secret', 'shield-alt|Shield|security,protection,antivirus',
            'fingerprint|Fingerprint|biometric,identity,auth',
            'passport|Passport|identity,travel', 'mask|Mask|anonymous,privacy',
            'virus|Virus|malware,infection', 'virus-slash|No virus|clean,antivirus',
            'skull|Skull|danger,threat,dead', 
            'door-closed|Door closed|access,closed', 'door-open|Door open|access,entry', 'archway|Gateway|entrance,portal',
            'stamp|Stamp|approve,official,seal', 'file-signature|Signature|sign,contract', 'signature|Signature mark|sign',
            'balance-scale-left|Compliance|legal,policy,audit', 'gavel|Gavel|law,legal,policy', 'certificate|Certificate|ssl,cert,badge',
            'hand-paper|Stop hand|halt,deny', 'shield-virus|Shield virus|antivirus,protection',
            'search-location|Investigate|search,forensics', 'binoculars|Binoculars|monitor,watch,observe',
            'low-vision|Low vision|visibility,watch', 'universal-access|Accessibility|a11y,access',
            'vector-square|Perimeter|boundary,zone',
        ],
        'files' => [
            'file|File|document,blank', 'file-alt|Document|text,file,page', 'file-pdf|PDF|document,acrobat', 'file-word|Word document|doc,docx',
            'file-excel|Excel sheet|xls,spreadsheet,xlsx', 'file-powerpoint|PowerPoint|ppt,slides,presentation',
            'file-image|Image file|picture,photo', 'file-archive|Archive file|zip,compressed', 'file-audio|Audio file|sound,music',
            'file-video|Video file|movie,media', 'file-code|Code file|source,script', 'file-csv|CSV file|data,export,spreadsheet',
            'file-contract|Contract|agreement,legal,sla', 'file-invoice|Invoice|bill,billing', 'file-invoice-dollar|Invoice dollar|bill,payment,billing',
            'file-download|File download|export,save', 'file-upload|File upload|import,attach', 'file-export|File export|send,save',
            'file-import|File import|load,upload', 'file-medical|Medical file|record,health', 'file-prescription|Prescription|rx,medical',
            'folder|Folder|directory,category', 'folder-open|Open folder|directory,browse', 'folder-plus|New folder|add,create',
            'folder-minus|Remove folder|delete', 'archive|Archive|box,storage,history', 'book|Book|manual,documentation,knowledge',
            'book-open|Open book|read,documentation,kb', 'journal-whills|Journal|log,notebook', 'atlas|Atlas|reference,map,book',
            'newspaper|Newspaper|news,announcement,article', 'save|Save|disk,store',
            'copy|Copy|duplicate,clone', 'paste|Paste|clipboard,insert', 'cut|Cut|scissors,remove', 'download|Download|get,save',
            'upload|Upload|send,put', 'edit|Edit|pencil,change,modify', 'pen|Pen|write,edit', 'pen-fancy|Fancy pen|write,sign',
            'trash|Trash|delete,remove,bin', 'trash-alt|Trash can|delete,remove,bin', 'trash-restore|Restore|undelete,recover',
            'images|Images|pictures,gallery', 'image|Image|picture,photo', 'receipt|Receipt|purchase,proof,bill',
            'scroll|Scroll|document,policy,history', 'bible|Bible|book,reference', 'quran|Book religious|book',
            'paragraph|Paragraph|text,writing', 'font|Font|text,typography', 'spell-check|Spell check|proofread,text',
            'stream|Stream|feed,activity,timeline',
        ],
        'communication' => [
            'envelope|Email|mail,message,letter', 'envelope-open|Open email|read,mail', 'envelope-open-text|Email text|message,newsletter',
            'paper-plane|Send|submit,message,deliver', 'comment|Comment|message,chat', 'comment-alt|Message|chat,note',
            'comment-slash|No comments|muted,chat',
            'sms|SMS|text message,mobile', 'phone-volume|Ringing|call,voip', 'phone-slash|Phone off|hangup,declined',
            'voicemail|Voicemail|message,call', 'mail-bulk|Bulk mail|mailing,campaign', 'bullhorn|Announcement|bullhorn,broadcast,news',
            'rss|RSS|feed,subscribe', 'share|Share|forward,send', 'share-alt|Share alt|forward,network', 'reply|Reply|respond,answer',
            'reply-all|Reply all|respond,answer', 'retweet|Retweet|repeat,forward', 'podcast|Podcast|audio,broadcast',
            'volume-up|Volume up|sound,loud', 'volume-mute|Muted|silent,sound off', 'quote-left|Quote|citation,testimonial',
            'language|Language|translation,locale,i18n', 'blog|Blog|article,post', 'rss-square|RSS square|feed',
            'share-square|Share square|forward,external', 'external-link-alt|External link|open,new window',
            'video-slash|No video|camera off', 'chalkboard|Chalkboard|presentation,meeting',
            'glass-cheers|Celebrate|party,cheers,social', 'american-sign-language-interpreting|Sign language|accessibility,deaf',
            'closed-captioning|Captions|subtitles,accessibility', 
        ],
        'money' => [
            'dollar-sign|Dollar|money,usd,price', 'euro-sign|Euro|money,eur', 'pound-sign|Pound|money,gbp', 'yen-sign|Yen|money,jpy',
            'rupee-sign|Rupee|money,inr', 'ruble-sign|Ruble|money,rub', 'won-sign|Won|money,krw', 'lira-sign|Lira|money,try',
            'coins|Coins|money,cash,change', 'money-bill|Banknote|cash,money', 'money-bill-wave|Money wave|cash,payment',
            'money-check|Check payment|cheque,payment', 'money-check-alt|Check alt|cheque,payment', 'credit-card|Credit card|payment,billing,card',
            'wallet|Wallet|funds,payment', 'piggy-bank|Piggy bank|savings,budget', 'hand-holding-usd|Hand with money|pay,donation,funding',
            'donate|Donate|give,charity', 'cash-register|Cash register|pos,sale,checkout', 
            'shopping-cart|Cart|buy,order,store', 'cart-plus|Add to cart|buy,order',
            'shopping-bag|Shopping bag|purchase,store', 'shopping-basket|Basket|purchase,store', 'store|Store|shop,retail,catalog',
            'store-alt|Storefront|shop,retail', 'percent|Percent|discount,rate,tax', 'percentage|Percentage|discount,tax',
            'calculator|Calculator|math,quote,estimate', 
            'funnel-dollar|Sales funnel|pipeline,leads', 'comment-dollar|Quote request|price,pricing', 'search-dollar|Price search|cost,lookup',
            'hand-holding|Offer|give,provide', 'landmark|Bank|institution,government', 
            'briefcase|Business|work,briefcase,portfolio', 'business-time|Billable time|hours,billable',
        ],
        'time' => [
            'clock|Clock|time,hour,sla', 'stopwatch|Stopwatch|timer,duration,response', 'hourglass|Hourglass|wait,timer,pending',
            'calendar|Calendar|date,schedule', 'calendar-alt|Calendar alt|date,schedule', 'calendar-day|Today|date,day',
            'calendar-week|Week|date,schedule', 'calendar-check|Calendar check|done,scheduled,booked',
            'calendar-plus|Calendar add|new,schedule', 'calendar-minus|Calendar remove|cancel', 'calendar-times|Calendar cancel|cancelled,missed',
            'history|History|past,log,audit', 
            'bed|Bed|sleep,off hours,pto', 'moon|Moon|night,dark,after hours', 'sun|Sun|day,bright,light',
            'cloud-sun|Partly sunny|weather', 'snowflake|Snowflake|winter,cold,freeze', 
            'birthday-cake|Birthday|anniversary,celebrate', 'glass-martini|Happy hour|drink,party', 'coffee|Coffee|break,morning',
            'umbrella-beach|Vacation|holiday,pto,leave', 'plane-departure|Departure|travel,leave,out of office',
            'plane-arrival|Arrival|travel,return', 'sync|Recurring|repeat,loop', 
            'undo|Rewind|back,previous', 'step-forward|Next|skip,advance', 'step-backward|Previous|back',
            'fast-forward|Fast forward|speed,skip', 'forward|Forward|next', 'backward|Backward|previous',
        ],
        'places' => [
            'building|Building|office,company,organization', 'city|City|urban,locations,clients', 
            'house-user|Home office|remote,work from home', 'hotel|Hotel|lodging,stay', 'hospital|Hospital|healthcare,medical',
            'school|School|education,training', 'university|University|college,campus,institution', 'industry|Factory|industrial,manufacturing',
            'warehouse|Warehouse|storage,inventory,depot', 'church|Church|building,worship',
            'monument|Monument|landmark', 'map|Map|location,route',
            'map-marker-alt|Map pin|location,site,address', 'map-marked-alt|Marked map|location,site', 'map-pin|Pin|location',
            'map-signs|Signpost|directions,navigate', 'compass|Compass|direction,navigate', 'location-arrow|Navigation|gps,directions',
            'globe|Globe|world,web,internet', 'globe-americas|Americas|world,global', 'globe-europe|Europe|world,global',
            'globe-asia|Asia|world,global', 'route|Route|path,directions', 'road|Road|route,path', 'car|Car|vehicle,drive,onsite',
            'car-side|Car side|vehicle', 'truck|Truck|delivery,shipping,vehicle', 'shuttle-van|Van|vehicle,shuttle,field',
            'bus|Bus|transit,vehicle', 'train|Train|rail,transit', 'subway|Subway|metro,transit', 'taxi|Taxi|cab,ride',
            'motorcycle|Motorcycle|bike,vehicle', 'bicycle|Bicycle|bike', 'ship|Ship|boat,shipping', 'plane|Plane|flight,travel,air',
            'helicopter|Helicopter|air,flight', 'space-shuttle|Space shuttle|rocket,launch', 'parking|Parking|lot,car',
            'gas-pump|Fuel|gas,petrol,mileage', 'campground|Campground|camp,remote', 'mountain|Mountain|remote,outdoors',
            'couch|Couch|lounge,furniture', 'chair|Chair|furniture,seat,desk',
        ],
        'tools' => [
            'cog|Gear|settings,configure,options', 'cogs|Gears|settings,configuration,automation', 
            'tools|Tools|repair,maintenance,hammer', 'screwdriver|Screwdriver|tool,fix,repair',
            'hammer|Hammer|build,tool,fix', 'sliders-h|Sliders|settings,adjust,tune', 'ruler|Ruler|measure,size',
            'ruler-combined|Ruler combined|measure', 'drafting-compass|Compass tool|design,draw', 'paint-brush|Paint brush|design,style,theme',
            'paint-roller|Paint roller|design,theme', 'palette|Palette|colors,theme,design', 'fill-drip|Fill|color,paint', 'brush|Brush|paint,design',
            'eraser|Eraser|clear,remove', 'pencil-alt|Pencil|edit,write', 'pen-nib|Pen nib|design,write', 'highlighter|Highlighter|mark,emphasize',
            'marker|Marker|draw,highlight', 'broom|Broom|clean,clear,cleanup',
            'flask|Flask|test,lab,experiment', 'vial|Vial|test,lab', 'microscope|Microscope|inspect,research,analyze',
            'atom|Atom|science,core', 'dna|DNA|science,pattern', 'brain|Brain|ai,think,intelligence', 
            'search-plus|Zoom in|magnify,enlarge', 'search-minus|Zoom out|reduce', 'expand|Expand|fullscreen,enlarge',
            'compress|Compress|shrink,collapse', 'crop|Crop|trim,image', 'swatchbook|Swatches|colors,theme', 'eye-dropper|Eyedropper|color,pick',
            'bezier-curve|Curve|design,vector',
            'oil-can|Oil can|maintenance,lubricate', 'pump-soap|Sanitizer|clean,hygiene',
            'spray-can|Spray|clean,paint', 'magnet|Magnet|attract,pull', 
        ],
        'arrows' => [
            'arrow-up|Arrow up|raise,increase,escalate', 'arrow-down|Arrow down|lower,decrease', 'arrow-left|Arrow left|back,previous',
            'arrow-right|Arrow right|forward,next', 'arrow-circle-up|Circle up|raise,escalate', 'arrow-circle-down|Circle down|lower',
            'arrow-circle-left|Circle left|back', 'arrow-circle-right|Circle right|next', 'arrow-alt-circle-up|Outlined circle up|raise',
            'arrow-alt-circle-down|Outlined circle down|lower', 'chevron-up|Chevron up|collapse,up', 'chevron-down|Chevron down|expand,down',
            'chevron-left|Chevron left|back', 'chevron-right|Chevron right|next', 'angle-double-up|Double up|top,escalate',
            'angle-double-down|Double down|bottom', 'angle-double-left|Double left|first', 'angle-double-right|Double right|last',
            'caret-up|Caret up|sort', 'caret-down|Caret down|dropdown,sort', 'long-arrow-alt-up|Long arrow up|raise',
            'long-arrow-alt-down|Long arrow down|lower', 'long-arrow-alt-left|Long arrow left|back', 'long-arrow-alt-right|Long arrow right|next',
            'exchange-alt|Exchange|swap,transfer,sync', 'arrows-alt|Move|drag,resize,all directions', 'arrows-alt-h|Horizontal arrows|resize,width',
            'arrows-alt-v|Vertical arrows|resize,height', 'random|Shuffle|random,route,mix', 'sort|Sort|order', 'sort-up|Sort up|ascending',
            'sort-down|Sort down|descending', 'level-up-alt|Level up|parent,promote', 'level-down-alt|Level down|child,demote',
            'sign-in-alt|Sign in|login,enter', 'sign-out-alt|Sign out|logout,exit', 'external-link-square-alt|External square|open',
            'square|Square|shape,box', 'square-full|Solid square|shape',
            'plus|Plus|add,new,create', 'minus|Minus|remove,subtract', 'plus-circle|Add circle|new,create', 'minus-circle|Remove circle|subtract',
            'plus-square|Add square|new', 'minus-square|Subtract square|remove', 'equals|Equals|same,equal', 'divide|Divide|math',
            'not-equal|Not equal|different', 'greater-than|Greater than|compare', 'less-than|Less than|compare',
            'ellipsis-h|More horizontal|menu,options', 'ellipsis-v|More vertical|menu,options', 'shapes|Shapes|design,objects',
            'draw-polygon|Polygon|design,vector', 'star-of-life|Star of life|medical,asterisk',
        ],
        'nature' => [
            'leaf|Leaf|green,eco,nature', 'seedling|Seedling|grow,new,start', 'tree|Tree|nature,forest', 'recycle|Recycle|reuse,green',
            'water|Water|wave,liquid', 'tint|Droplet|water,liquid,drop', 'wind|Wind|air,breeze',
            'cloud-rain|Rain|weather,storm', 'cloud-sun-rain|Mixed weather|weather', 'meteor|Meteor|space,impact',
            'rainbow|Rainbow|colors,pride', 'umbrella|Umbrella|protection,coverage,insurance', 
            'paw|Paw|pet,animal', 'dog|Dog|pet,animal', 'cat|Cat|pet,animal', 'crow|Crow|bird,animal',
            'dove|Dove|peace,bird', 'feather|Feather|light,write', 'fish|Fish|animal,sea', 'frog|Frog|animal', 'horse|Horse|animal',
            'dragon|Dragon|fantasy,beast', 'spider|Spider|bug,web', 'hippo|Hippo|animal', 'otter|Otter|animal',
            'apple-alt|Apple|fruit,food', 'carrot|Carrot|food,vegetable', 'pizza-slice|Pizza|food,lunch', 'hamburger|Burger|food,lunch',
            'utensils|Utensils|food,restaurant,lunch', 'mug-hot|Hot drink|coffee,tea,break', 'beer|Beer|drink,bar', 'cookie|Cookie|snack,browser',
            'ice-cream|Ice cream|dessert,treat', 'egg|Egg|food', 'cheese|Cheese|food', 'lemon|Lemon|fruit,sour',
            'hat-wizard|Wizard hat|magic,expert,guru', 'poo|Poo|joke,mess', 'theater-masks|Masks|drama,theater',
            'dice|Dice|random,game,chance', 'dice-d20|D20|game,roll', 'chess|Chess|strategy,game', 'chess-knight|Chess knight|strategy',
            'chess-king|Chess king|strategy,leader', 'futbol|Soccer|sport,team,football', 'basketball-ball|Basketball|sport', 'football-ball|Football|sport',
            'dumbbell|Dumbbell|fitness,gym', 'biking|Biking|exercise,sport', 'hiking|Hiking|walk,journey', 'running|Running|fast,sprint',
            'swimmer|Swimmer|sport', 'guitar|Guitar|music', 'music|Music|audio,song', 'drum|Drum|music', 'film|Film|movie,video',
            'spa|Spa|relax,wellness', 'heart-broken|Broken heart|sad,churn', 'pray|Pray|hope,wish',
            'peace|Peace|calm,harmony', 'yin-yang|Balance harmony|yin,yang', 'ring|Ring|jewelry,engagement', 'anchor|Anchor|stable,fixed,pin',
            'flag-usa|US flag|america,country', 'snowman|Snowman|winter,holiday', 'gifts|Gifts|holiday,presents',
            'sleigh|Sleigh|holiday,delivery', 'candy-cane|Candy cane|holiday', 'holly-berry|Holly|holiday',
        ],
    ];
    /** @var list<array{class:string,label:string,category:string,keywords:list<string>}>|null */
    private static ?array $entries = null;

    private function __construct()
    {
    }

    /** @return array<string,string> category key => human label, in display order */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * Every curated icon, in stable category-then-declaration order. An icon listed in several categories appears
     * once, in the first category that lists it.
     *
     * @return list<array{class:string,label:string,category:string,keywords:list<string>}>
     */
    public static function all(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $out = [];
        $seen = [];
        foreach (self::DATA as $category => $rows) {
            foreach ($rows as $row) {
                $parts = explode('|', $row);
                $class = 'fa-' . $parts[0];
                if (isset($seen[$class])) {
                    continue;
                }
                $seen[$class] = true;
                $keywords = ($parts[2] ?? '') === '' ? [] : array_values(array_unique(explode(',', $parts[2])));
                $out[] = ['class' => $class, 'label' => $parts[1], 'category' => $category, 'keywords' => $keywords];
            }
        }
        return self::$entries = $out;
    }

    /** @return array<string,list<array{class:string,label:string,category:string,keywords:list<string>}>> */
    public static function byCategory(): array
    {
        $grouped = [];
        foreach (self::CATEGORIES as $key => $_label) {
            $grouped[$key] = [];
        }
        foreach (self::all() as $entry) {
            $grouped[$entry['category']][] = $entry;
        }
        return $grouped;
    }

    /**
     * Case-insensitive search over class, label and keywords. Ranking (best first): exact name/label, name or label
     * prefix, word-prefix in the label, keyword exact/prefix, substring of name or label, keyword substring; ties keep
     * catalog order, so results are deterministic. An empty query returns the catalog (optionally one category).
     *
     * @return list<array{class:string,label:string,category:string,keywords:list<string>}>
     */
    public static function search(string $query, ?string $category = null, int $limit = 60): array
    {
        if ($limit < 1) {
            return [];
        }
        $q = strtolower(trim($query));
        $qName = str_starts_with($q, 'fa-') ? substr($q, 3) : $q;
        $ranked = [];
        foreach (self::all() as $i => $entry) {
            if ($category !== null && $category !== '' && $entry['category'] !== $category) {
                continue;
            }
            $score = $q === '' ? 0 : self::score($entry, $q, $qName);
            if ($score !== null) {
                $ranked[] = [$score, $i, $entry];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn (array $r): array => $r[2], array_slice($ranked, 0, $limit));
    }

    /**
     * Catalog membership only (is this one of the curated icons). Expects a canonical class such as 'fa-fire';
     * use {@see normalize()} first for user input. A valid-but-uncurated class is NOT catalogued, yet is still
     * accepted by normalize().
     */
    public static function has(string $class): bool
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::all() as $entry) {
                $index[$entry['class']] = true;
            }
        }
        return isset($index[$class]);
    }

    /**
     * Canonicalise a stored/submitted icon value to a single safe 'fa-xxx' class. Accepts 'fa-fire', 'fas fa-fire',
     * 'fa fa-fire', 'fa-solid fa-fire' and bare 'fire' (trimmed, case-insensitive). Any syntactically valid class
     * is accepted even when it is not in the curated catalog (see {@see has()}). Empty, over-long (more than
     * 50 chars) or invalid input (extra words, quotes, angle brackets, non-ASCII...) returns $default.
     */
    public static function normalize(?string $input, string $default = 'fa-filter'): string
    {
        if ($input === null) {
            return $default;
        }
        $value = strtolower(trim($input));
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return $default;
        }
        $tokens = preg_split('/\s+/', $value) ?: [];
        // A leading run of style tokens ('fas', 'fa', 'fa-solid'...) is dropped; exactly one name must remain.
        while ($tokens !== [] && count($tokens) > 1 && in_array($tokens[0], self::STYLE_TOKENS, true)) {
            array_shift($tokens);
        }
        if (count($tokens) !== 1 || in_array($tokens[0], self::STYLE_TOKENS, true)) {
            return $default;
        }
        $class = str_starts_with($tokens[0], 'fa-') ? $tokens[0] : 'fa-' . $tokens[0];
        if (strlen($class) > self::MAX_LENGTH || preg_match(self::PATTERN, $class) !== 1) {
            return $default;
        }
        return $class;
    }

    /** Compact JSON for the client-side picker: {"version":N,"categories":{...},"icons":[{c,l,g,k},...]} ASCII-safe. */
    public static function toJson(): string
    {
        $icons = [];
        foreach (self::all() as $e) {
            $icons[] = ['c' => $e['class'], 'l' => $e['label'], 'g' => $e['category'], 'k' => $e['keywords']];
        }
        $json = json_encode(
            ['version' => self::VERSION, 'categories' => self::CATEGORIES, 'icons' => $icons],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return $json;
    }

    /** @param array{class:string,label:string,category:string,keywords:list<string>} $e */
    private static function score(array $e, string $q, string $qName): ?int
    {
        $name = substr($e['class'], 3);
        $label = strtolower($e['label']);
        if ($name === $qName || $label === $q) {
            return 0;
        }
        if (str_starts_with($name, $qName) || str_starts_with($label, $q)) {
            return 1;
        }
        foreach (preg_split('/[\s-]+/', $label) ?: [] as $word) {
            if ($word !== '' && str_starts_with($word, $q)) {
                return 2;
            }
        }
        foreach ($e['keywords'] as $k) {
            if ($k === $q || str_starts_with($k, $q)) {
                return 3;
            }
        }
        if (str_contains($name, $qName) || str_contains($label, $q)) {
            return 4;
        }
        foreach ($e['keywords'] as $k) {
            if (str_contains($k, $q)) {
                return 5;
            }
        }
        return null;
    }
}
