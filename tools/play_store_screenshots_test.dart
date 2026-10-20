import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:coxbanglo/main.dart';
import 'package:coxbanglo/core/api.dart';
import 'package:coxbanglo/core/session.dart';
import 'package:coxbanglo/widgets/common.dart';

// Store assets only: synthetic records, the actual production widgets, no network.
class ScreenshotApi extends Api {
  final rooms = <Json>[
    {'id': 1, 'code': '201', 'name': 'বাঁকখালী', 'available': true, 'capacity': 2, 'housekeeping': 'ready', 'ac_type': 'ac', 'bookable': true},
    {'id': 2, 'code': '202', 'name': 'মাতামুহুরী', 'available': false, 'capacity': 2, 'housekeeping': 'ready', 'ac_type': 'ac', 'bookable': true},
    {'id': 3, 'code': '203', 'name': 'ইনানী', 'available': true, 'capacity': 3, 'housekeeping': 'ready', 'ac_type': 'non_ac', 'bookable': true},
    {'id': 4, 'code': '301', 'name': 'হিমছড়ি', 'available': false, 'capacity': 2, 'housekeeping': 'cleaning', 'ac_type': 'ac', 'bookable': true},
    {'id': 5, 'code': '302', 'name': 'সোনাদিয়া', 'available': true, 'capacity': 2, 'housekeeping': 'ready', 'ac_type': 'ac', 'bookable': true},
  ];
  late final bookings = <Json>[
    {'id': 1, 'number': 'CB-DEMO-001', 'status': 'confirmed', 'guest_snapshot': {'name': 'Sample Guest A'}, 'check_in_at': '2026-10-19 14:00:00', 'check_out_at': '2026-10-21 12:00:00', 'rooms': [rooms[1]]},
    {'id': 2, 'number': 'CB-DEMO-002', 'status': 'pending_approval', 'guest_snapshot': {'name': 'Sample Guest B'}, 'check_in_at': '2026-10-20 14:00:00', 'check_out_at': '2026-10-22 12:00:00', 'rooms': [rooms[0]]},
    {'id': 3, 'number': 'CB-DEMO-003', 'status': 'checked_in', 'guest_snapshot': {'name': 'Sample Guest C'}, 'check_in_at': '2026-10-19 14:00:00', 'check_out_at': '2026-10-20 12:00:00', 'rooms': [rooms[3]]},
  ];
  @override
  Future<Json> get(String path, {Json? query}) async {
    switch(path) {
      case 'dashboard': return {'data': {'rooms': 14, 'bookable_rooms': 10, 'pending': 2, 'occupied': 4, 'arrivals': 3, 'departures': 2, 'collections_minor': 1850000, 'receivables_minor': 425000, 'tariff_ready': true}};
      case 'catalog': return {'data': {'rooms': rooms, 'categories': [{'id': 1, 'code': 'A', 'name': 'Office guests'}], 'tariffs': []}};
      case 'availability': return {'data': rooms};
      case 'bookings': return {'data': bookings};
      case 'calendar': return {'data': {'bookings': bookings, 'entries': [
        {'room_id': 2, 'booking_id': 1, 'starts_at': '2026-10-19 14:00:00', 'ends_at': '2026-10-21 12:00:00'},
        {'room_id': 4, 'booking_id': 3, 'starts_at': '2026-10-19 14:00:00', 'ends_at': '2026-10-20 12:00:00'},
      ]}};
      case 'guests': return {'data': [
        {'id':1,'name':'Sample Guest A','mobile':'+880 1XXX XXXXXX','designation':'Visiting officer','organization':'Sample office','email':'guest-a@example.com'},
        {'id':2,'name':'Sample Guest B','mobile':'+880 1XXX XXXXXX','designation':'Project coordinator','organization':'Sample office','email':'guest-b@example.com'},
        {'id':3,'name':'Sample Guest C','mobile':'+880 1XXX XXXXXX','designation':'Staff member','organization':'Sample office','email':'guest-c@example.com'},
      ]};
      default: return {'data': []};
    }
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(() async {
    for (final entry in {'Roboto':'assets/fonts/Roboto.ttf','NotoSansBengali':'assets/fonts/NotoSansBengali.ttf','MaterialIcons':'fonts/MaterialIcons-Regular.otf'}.entries) {
      await (FontLoader(entry.key)..addFont(rootBundle.load(entry.value))).load();
    }
  });
  testWidgets('Capture five real phone screens for the Play listing', (tester) async {
    tester.view.physicalSize = const Size(1080,1920);
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final key = GlobalKey();
    const directory = 'G:/laragon/www/coxbazar-banglo/cox-laravel-application/public/images/store-screenshots';
    Directory(directory).createSync(recursive: true);
    Future<void> mount({ThemeMode theme=ThemeMode.light}) async {
      final api=ScreenshotApi()..baseUrl='https://demo.invalid/api/v1';
      final session=Session(api:api)..starting=false..theme=theme..user={'id':999,'name':'Demo Administrator','role':'admin'}..catalog={'rooms':api.rooms,'categories':[],'tariffs':[]};
      await tester.pumpWidget(RepaintBoundary(key:key,child:CoxBangloApp(key:UniqueKey(),session:session)));
      await tester.pumpAndSettle();
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds:200)));
      await tester.pumpAndSettle();
      expect(tester.takeException(),isNull);
    }
    Future<void> capture(String name) async {
      await tester.runAsync(() async {
        for(final element in tester.elementList(find.byType(Image)).toList()) {
          Object? failure;
          await precacheImage((element.widget as Image).image,element,onError:(error,stack)=>failure=error);
          if(failure!=null) throw StateError('Image failed to load: $failure');
        }
      });
      await tester.pumpAndSettle();
      expect(tester.takeException(),isNull);
      final boundary=key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
      await tester.runAsync(() async {
        final image=await boundary.toImage(pixelRatio:2);
        expect(image.width,1080);expect(image.height,1920);
        final bytes=await image.toByteData(format:ui.ImageByteFormat.png);
        await File('$directory/$name.png').writeAsBytes(bytes!.buffer.asUint8List());
        image.dispose();
      });
    }
    Future<void> tab(int index) async {
      await tester.tap(find.byType(NavigationDestination).at(index));
      await tester.pumpAndSettle();
    }
    await mount();
    await capture('01-dashboard');
    await tab(2);
    await capture('02-bookings');
    await tab(1);
    await capture('03-calendar');
    await mount(theme:ThemeMode.dark);
    await tab(3);
    await capture('04-rooms-dark');
    await mount();
    tester.state<ScaffoldState>(find.byType(Scaffold).first).openDrawer();
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ListTile,'Guests'));
    await tester.pumpAndSettle();
    await capture('05-guests');
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
