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

import 'play_store_screenshots_test.dart' show ScreenshotApi;

class WorkspacePreviewApi extends ScreenshotApi {
  @override
  Future<Json> get(String path,{Json? query}) async {
    if(path=='users')return {'data':[
      {'id':1,'name':'Sample Administrator','email':'admin@example.com','role':'admin','active':true},
      {'id':2,'name':'Sample Front Desk','email':'desk@example.com','role':'staff_admin','active':true},
    ]};
    if(path=='notifications')return {'data':[
      {'id':1,'booking_id':1,'channel':'email','status':'delivered','destination':'guest-a@example.com','created_at':'2026-10-19 10:30:00','provider_id':'demo'},
      {'id':2,'booking_id':2,'channel':'email','status':'failed','destination':'guest-b@example.com','created_at':'2026-10-19 11:45:00','provider_id':null,'last_error':'Delivery failed. Check the destination before retrying.'},
    ]};
    if(path=='audit-logs')return {'data':[
      {'id':1,'action':'booking_approved','entity':'booking','entity_id':1,'actor_id':1,'created_at':'2026-10-19 10:25:00'},
      {'id':2,'action':'guest_created','entity':'guest','entity_id':2,'actor_id':2,'created_at':'2026-10-19 09:40:00'},
    ]};
    return super.get(path,query:query);
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
    const directory = 'G:/laragon/www/coxbazar-banglo/cox-laravel-application/public/images/workspace-previews';
    Directory(directory).createSync(recursive: true);
    Future<void> mount({ThemeMode theme=ThemeMode.light}) async {
      final api=WorkspacePreviewApi()..baseUrl='https://demo.invalid/api/v1';
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
    await tab(3);
    await capture('03-rooms');
    for (final entry in {'Guests':'04-guests','Staff':'05-staff','Notifications':'06-notifications','Audit trail':'07-audit-trail'}.entries) {
      tester.state<ScaffoldState>(find.byType(Scaffold).first).openDrawer();
      await tester.pumpAndSettle();
      final nav=find.widgetWithText(ListTile,entry.key);
      await tester.scrollUntilVisible(nav,100,scrollable:find.descendant(of:find.byType(Drawer),matching:find.byType(Scrollable)).first);
      await tester.tap(nav);
      await tester.pumpAndSettle();
      await capture(entry.value);
    }
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
