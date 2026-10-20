import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:coxbanglo/main.dart';
import 'package:coxbanglo/core/api.dart';
import 'package:coxbanglo/core/session.dart';

import 'play_store_screenshots_test.dart' show ScreenshotApi;

// Actual responsive tablet UI at 720 x 1280 logical pixels, rendered at 2x.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(() async {
    for (final entry in {
      'Roboto': 'assets/fonts/Roboto.ttf',
      'NotoSansBengali': 'assets/fonts/NotoSansBengali.ttf',
      'MaterialIcons': 'fonts/MaterialIcons-Regular.otf',
    }.entries) {
      await (FontLoader(
        entry.key,
      )..addFont(rootBundle.load(entry.value))).load();
    }
  });
  testWidgets('Capture five real 7-inch tablet screens for the Play listing', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 2560);
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final key = GlobalKey();
    const directory =
        'G:/laragon/www/coxbazar-banglo/cox-laravel-application/public/images/store-screenshots-7-inch-tablet';
    Directory(directory).createSync(recursive: true);
    Future<void> mount({ThemeMode theme = ThemeMode.light}) async {
      final api = ScreenshotApi()..baseUrl = 'https://demo.invalid/api/v1';
      final session = Session(api: api)
        ..starting = false
        ..theme = theme
        ..user = {'id': 999, 'name': 'Demo Administrator', 'role': 'admin'}
        ..catalog = {'rooms': api.rooms, 'categories': [], 'tariffs': []};
      await tester.pumpWidget(
        RepaintBoundary(
          key: key,
          child: CoxBangloApp(key: UniqueKey(), session: session),
        ),
      );
      await tester.pumpAndSettle();
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 200)),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
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
      expect(tester.takeException(), isNull);
      final boundary =
          key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
      await tester.runAsync(() async {
        final image = await boundary.toImage(pixelRatio: 2);
        expect(image.width, 1440);
        expect(image.height, 2560);
        final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
        await File('$directory/$name.png')
            .writeAsBytes(bytes!.buffer.asUint8List());
        image.dispose();
      });
    }

    Future<void> tab(int index) async {
      await tester.tap(
        find.byTooltip(['Dashboard', 'Calendar', 'Bookings', 'Rooms'][index]),
      );
      await tester.pumpAndSettle();
    }

    await mount();
    await capture('01-dashboard');
    await tab(2);
    await capture('02-bookings');
    await tab(1);
    await capture('03-calendar');
    await mount(theme: ThemeMode.dark);
    await tab(3);
    await capture('04-rooms-dark');
    await mount();
    await tester.tap(find.byTooltip('Guests'));
    await tester.pumpAndSettle();
    await capture('05-guests');
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
