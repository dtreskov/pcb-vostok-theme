/**
 * This file adds some LIVE preview to the Under Construction Maintenance Mode in WordPress Customizer.
 * @version 1.5.1
 */
(function($) {
    var ucmmSocialPreviewMap = {
        ucmm_facebook: { selector: ".ucmm-facebook-icon", display: "inline-block" },
        ucmm_twitter: { selector: ".ucmm-twitter-icon", display: "inline-flex" },
        ucmm_linkedin: { selector: ".ucmm-linkedin-icon", display: "inline-block" },
        ucmm_youtube: { selector: ".ucmm-youtube-icon", display: "inline-block" },
        ucmm_instagram: { selector: ".ucmm-instagram-icon", display: "inline-block" },
        ucmm_pinterest: { selector: ".ucmm-pinterest-icon", display: "inline-block" },
        ucmm_codepen: { selector: ".ucmm-codepen-icon", display: "inline-block" }
    };

    function ucmmGetPreviewContents() {
        var $iframe = $("#customize-preview iframe");

        if (!$iframe.length) {
            return $();
        }

        return $iframe.contents();
    }

    function ucmmApplyLogoSize() {
        var $preview = ucmmGetPreviewContents();
        var $img = $preview.find(".ucmm-logo img");
        var widthSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_logo_width]");
        var heightSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_logo_height]");
        var width = widthSetting ? widthSetting.get() : "";
        var height = heightSetting ? heightSetting.get() : "";

        if (!$img.length) {
            return;
        }

        $img.css({
            width: width ? width : "",
            height: height ? height : ""
        });
    }

    function ucmmUpdateSocialPreview(settingKey, url) {
        var config = ucmmSocialPreviewMap[settingKey];
        var $preview = ucmmGetPreviewContents();

        if (!config || !$preview.length) {
            return;
        }

        var $icon = $preview.find(config.selector);

        if (!$icon.length) {
            return;
        }

        $icon.attr("href", url || "#");

        if ("" === url) {
            $icon.css({ display: "none" });
        } else {
            $icon.css({ display: config.display });
        }

        if ($preview[0] && $preview[0].dispatchEvent) {
            $preview[0].dispatchEvent(new CustomEvent("ucmmSocialLinksUpdated"));
        }
    }

    function ucmmInitSocialPreview() {
        $.each(ucmmSocialPreviewMap, function(settingKey) {
            var setting = wp.customize("ucmm_wpbrigade_customization[" + settingKey + "]");

            if (setting) {
                ucmmUpdateSocialPreview(settingKey, setting.get());
            }
        });
    }

    $(document).ready(function() {
        // header text h1
        // wp.customize( 'ucmm_wpbrigade_customization[ucmm_logo_width]', function( value ) {
        // 	value.bind( function( newval ) {
        //
        //     if ( '' != newval) {
        //       $('#customize-preview iframe').contents().find( '.ucmm-logo img' ).css( 'width',  newval );
        //     }
        //
        // 	} );
        // } );
        // // header text h1
        // wp.customize( 'ucmm_wpbrigade_customization[ucmm_logo_height]', function( value ) {
        // 	value.bind( function( newval ) {
        //
        //     if ( '' != newval) {
        //       $('#customize-preview iframe').contents().find( '.ucmm-logo img' ).css( 'height',  newval );
        //     }
        //
        // 	} );
        // } );

		// Check if user has checked the option of footer text or not.
		if ( !$('#customize-control-ucmm_wpbrigade_customization-ucmm_display_footer_text input').is(':checked') ) {
			$('#customize-control-ucmm_wpbrigade_customization-ucmm_display_footer_text_position').hide();
			$('#customize-control-ucmm_wpbrigade_customization-ucmm_love_text_color').hide();
			$('#customize-control-ucmm_wpbrigade_customization-ucmm_love_hover_color').hide();
		}

		// header text h1
        wp.customize("ucmm_wpbrigade_customization[header_text]", function(value) {
            value.bind(function(newval) {
                var $heading = $("#customize-preview iframe")
                    .contents()
                    .find(".ucmm-content h1");

                if ("" !== newval) {
                    $heading.html(newval);
                } else {
                    $heading.html(
                        typeof UCMM.header_text_default !== "undefined"
                            ? UCMM.header_text_default
                            : "COMING SOON"
                    );
                }
            });
        });

        // Subheading text.
        wp.customize("ucmm_wpbrigade_customization[footer_text]", function(value) {
            value.bind(function(newval) {
                var $subheading = $("#customize-preview iframe")
                    .contents()
                    .find(".ucmm-content .ucmm-subheading");

                if ("" !== newval && null != newval) {
                    var output = newval.replace(/<script>/gi, "");
                    $subheading.html(output);
                } else {
                    $subheading.empty();
                }
            });
        });
        // footer text "Love" hide and show
        wp.customize(
            "ucmm_wpbrigade_customization[ucmm_display_footer_text]",
            function(value) {
                value.bind(function(newval) {
                    if (true == newval) {
                        $("#customize-preview iframe")
						.contents()
						.find(".footer-love")
						.show();

						$("#customize-control-ucmm_wpbrigade_customization-ucmm_display_footer_text_position").show();
						$("#customize-control-ucmm_wpbrigade_customization-ucmm_love_text_color").show();
						$("#customize-control-ucmm_wpbrigade_customization-ucmm_love_hover_color").show();

						if ( $("#customize-preview iframe").contents().find(".footer-love").length == 0) {
							$("#customize-preview iframe")
							.contents()
							.find("body").append( '<footer class="footer-love">' + UCMM.customizer_strings[0] + '<a href="https://wpbrigade.com/wordpress/plugins/under-construction-maintenance-mode/" target="_blank">' + UCMM.customizer_strings[1] + '</a></footer>' );
						}
						ucmmSyncLayoutBodyClasses();
                    } else {
                        $("#customize-preview iframe")
						.contents()
						.find(".footer-love")
						.hide();
						$("#customize-control-ucmm_wpbrigade_customization-ucmm_display_footer_text_position").hide();
						$("#customize-control-ucmm_wpbrigade_customization-ucmm_love_text_color").hide();
						$("#customize-control-ucmm_wpbrigade_customization-ucmm_love_hover_color").hide();
						ucmmSyncLayoutBodyClasses();

                    }
                });
            }
        );
		wp.customize(
            "ucmm_wpbrigade_customization[ucmm_display_footer_text_position]",
            function(value) {
                value.bind(function(newval) {
                        $("#customize-preview iframe")
						.contents()
						.find(".footer-love").css( 'text-align', newval );
						ucmmSyncLayoutBodyClasses();
                });
            }
        );

        function ucmmApplyLoveTextColor() {
            var color = wp.customize("ucmm_wpbrigade_customization[ucmm_love_text_color]").get();
            var hoverColorSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_love_hover_color]");
            var hoverColor = hoverColorSetting ? hoverColorSetting.get() : "";
            var $doc = $("#customize-preview iframe").contents();
            var $live = $doc.find("#ucmm-customizer-live-love-color");

            if (!$live.length) {
                $live = $('<style id="ucmm-customizer-live-love-color"></style>').appendTo($doc.find("head"));
            }

            var baseColor = color && String(color).trim() !== "" ? color : "#1F2557";
            var hover = hoverColor && String(hoverColor).trim() !== "" ? hoverColor : "#3BB9FF";
            $live.text(
                ".footer-love { color: " + baseColor + "; }" +
                ".footer-love a { color: inherit; }" +
                ".footer-love a:hover { color: " + hover + "; }"
            );
        }

        // footer text "Love" color.
		wp.customize("ucmm_wpbrigade_customization[ucmm_love_text_color]", function(value) {
            value.bind(function() {
                ucmmApplyLoveTextColor();
            });
        });

        wp.customize("ucmm_wpbrigade_customization[ucmm_love_hover_color]", function(value) {
            value.bind(function() {
                ucmmApplyLoveTextColor();
            });
        });

        function ucmmApplyBackground() {
            var bgColor = wp.customize("ucmm_wpbrigade_customization[ucmm_background_color]").get();
            var bg = wp.customize("ucmm_wpbrigade_customization[setting_background]").get();
            var cover = wp.customize("ucmm_wpbrigade_customization[background_cover]").get();
            var repeat = wp.customize("ucmm_wpbrigade_customization[background_repeat]").get();
            var position = wp.customize("ucmm_wpbrigade_customization[background_position]").get();
            var attachment = wp.customize("ucmm_wpbrigade_customization[background_attachment]").get();
            var css = "body.ucmm-body {";

            if (bg && String(bg).trim() !== "") {
                css += "background-color:transparent;";
                css += "background-image:url(" + bg + ");";
                css += "background-size:" + (cover || "auto") + ";";
                css += "background-repeat:" + (repeat || "no-repeat") + ";";
                css += "background-position:" + (position || "center") + ";";
                css += "background-attachment:" + (attachment || "scroll") + ";";
            } else if (bgColor && String(bgColor).trim() !== "") {
                css += "background-color:" + bgColor + ";";
                css += "background-image:none;";
            } else {
                css += "background-color:transparent;";
                css += "background-image:none;";
            }

            css += "}";

            var $doc = $("#customize-preview iframe").contents();
            var $live = $doc.find("#ucmm-customizer-live-bg");
            if (!$live.length) {
                $live = $('<style id="ucmm-customizer-live-bg"></style>').appendTo($doc.find("head"));
            }
            $live.text(css);
        }

        wp.customize("ucmm_wpbrigade_customization[ucmm_background_color]", function(value) {
            value.bind(function() {
                ucmmApplyBackground();
            });
        });

        wp.customize("ucmm_wpbrigade_customization[setting_background]", function(value) {
            value.bind(function() {
                ucmmApplyBackground();
            });
        });

		function ucmmSetVectorImage(newval) {
			var $container = $("#customize-preview iframe").contents().find(".ucmm-bottom-vector-container");
			var $img = $container.find(".ucmm-bottom-vector");

			if ("" !== newval && null != newval) {
				$container.css("display", "");
				$img.attr("src", newval).show();
			} else {
				$container.hide();
			}
			ucmmSyncLayoutBodyClasses();
		}

		function ucmmApplyVectorImage() {
			ucmmSetVectorImage(
				wp.customize("ucmm_wpbrigade_customization[ucmm_vector_image]").get()
			);
		}

		wp.customize("ucmm_wpbrigade_customization[ucmm_vector_image]", function(value) {
			value.bind(function(newval) {
				ucmmSetVectorImage(newval);
			});
		});

        //Background Cover 
        wp.customize("ucmm_wpbrigade_customization[background_cover]",
            function(value) {
                value.bind(function() {
                    ucmmApplyBackground();
                });
            });

        //Background Repeat
        wp.customize("ucmm_wpbrigade_customization[background_repeat]",
            function(value) {
                value.bind(function() {
                    ucmmApplyBackground();
                });
            });

        //background Position
        wp.customize("ucmm_wpbrigade_customization[background_position]",
            function(value) {
                value.bind(function() {
                    ucmmApplyBackground();
                });
            });

        wp.customize("ucmm_wpbrigade_customization[background_attachment]",
            function(value) {
                value.bind(function() {
                    ucmmApplyBackground();
                });
            });

        // change  logo image
        wp.customize("ucmm_wpbrigade_customization[ucmm_logo]", function(value) {
            value.bind(function(newval) {
                if ("" !== newval) {
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm-logo img")
                        .show();
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm-logo img")
                        .attr("src", newval);
                } else {
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm-logo img")
                        .hide();
                }
                ucmmSyncLayoutBodyClasses();
            });
        });
        wp.customize("ucmm_wpbrigade_customization[ucmm_logo_width]", function(value) {
            value.bind(function() {
                ucmmApplyLogoSize();
            });
        });

        wp.customize("ucmm_wpbrigade_customization[ucmm_logo_height]", function(value) {
            value.bind(function() {
                ucmmApplyLogoSize();
            });
        });

        //style apply
        wp.customize("ucmm_wpbrigade_customization[ucmm_custom_css]", function(value) {
            value.bind(function(newval) {
                if ('' !== newval) {
                    $("#customize-preview iframe")
                        .contents()
                        .find("style")
                        .append(newval);
                }
            });
        });
        //Header Color

        wp.customize("ucmm_wpbrigade_customization[ucmm_header_text_color]", function(value) {
            value.bind(function(newval) {
                if ('' !== newval) {
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm-content h1")
                        .css({ color: newval });
                }
            });
        });
        wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_text_color]", function(value) {
            value.bind(function(newval) {
                if ('' !== newval) {
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm_schedule_time")
                        .css({ color: newval });
                }
            });
        });
        wp.customize("ucmm_wpbrigade_customization[ucmm_footer_text_color]", function(value) {
            value.bind(function(newval) {
                if ('' !== newval) {
                    $("#customize-preview iframe")
                        .contents()
                        .find(".ucmm-content .ucmm-subheading, .ucmm-content .ucmm-subheading a")
                        .css({ color: newval });
                }
            });
        });
        function ucmmGetScheduleUtcOffset() {
            var offset = null;
            var $iframe = $("#customize-preview iframe");

            if ($iframe.length) {
                var win = $iframe[0].contentWindow;
                if (win && null != win.ucmmScheduleUtcOffset && !isNaN(parseInt(win.ucmmScheduleUtcOffset, 10))) {
                    offset = parseInt(win.ucmmScheduleUtcOffset, 10);
                }
            }

            if (null === offset && UCMM && null != UCMM.scheduleUtcOffset && !isNaN(parseInt(UCMM.scheduleUtcOffset, 10))) {
                offset = parseInt(UCMM.scheduleUtcOffset, 10);
            }

            if (null === offset) {
                offset = 0;
            }

            if (UCMM) {
                UCMM.scheduleUtcOffset = offset;
            }

            return offset;
        }

        function ucmmScheduleToTimestamp(datetimeLocal) {
            if (!datetimeLocal || typeof datetimeLocal !== "string") {
                return null;
            }
            var parts = datetimeLocal.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
            if (!parts) {
                return null;
            }
            var offsetSec = ucmmGetScheduleUtcOffset();
            return Date.UTC(
                parseInt(parts[1], 10),
                parseInt(parts[2], 10) - 1,
                parseInt(parts[3], 10),
                parseInt(parts[4], 10),
                parseInt(parts[5], 10)
            ) - (offsetSec * 1000);
        }

        function ucmmValidateScheduleRange() {
            var startSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_start]");
            var endSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_end]");
            var start = startSetting ? startSetting.get() : "";
            var end = endSetting ? endSetting.get() : "";
            var message = UCMM.scheduleInvalidMessage || "End time must be later than the start time.";
            var code = "ucmm_schedule_range";

            function setNotice(setting, text) {
                if (!setting || !setting.notifications) {
                    return;
                }
                setting.notifications.remove(code);
                if (text) {
                    setting.notifications.add(code, new wp.customize.Notification(code, {
                        message: text,
                        type: "error"
                    }));
                }
            }

            setNotice(startSetting, null);

            if (!start || !end) {
                setNotice(endSetting, null);
                return true;
            }

            var startMs = ucmmScheduleToTimestamp(start);
            var endMs = ucmmScheduleToTimestamp(end);
            if (null === startMs || null === endMs) {
                setNotice(endSetting, null);
                return true;
            }

            if (endMs <= startMs) {
                setNotice(endSetting, message);
                return false;
            }

            setNotice(endSetting, null);
            return true;
        }

        function ucmmSyncScheduleEndMin() {
            var start = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_start]").get();
            var $endInput = $("#customize-control-ucmm_wpbrigade_customization-ucmm_schedule_end input[type='datetime-local']");
            if ($endInput.length && start) {
                $endInput.attr("min", start);
            } else {
                $endInput.removeAttr("min");
            }
        }

        function ucmmEnsureSchedulePreviewWrap($doc) {
            var $wrap = $doc.find(".ucmm_schedule_time");
            if ($wrap.length) {
                return $wrap;
            }

            var $main = $doc.find(".ucmm-main");
            if (!$main.length) {
                return $();
            }

            $wrap = $('<div class="ucmm_schedule_time"></div>');
            var $content = $main.children(".ucmm-content");
            if ($content.length) {
                $content.after($wrap);
            } else {
                $main.append($wrap);
            }

            return $wrap;
        }

        function ucmmApplySchedulePreview() {
            var $iframe = $("#customize-preview iframe");
            if (!$iframe.length) {
                return;
            }

            var $doc = $iframe.contents();
            var showSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_show_end_time]");
            var showSchedule = showSetting ? showSetting.get() : false;
            var $wrap = ucmmEnsureSchedulePreviewWrap($doc);

            if (!$wrap.length) {
                return;
            }

            if (!showSchedule) {
                $wrap.hide().attr("aria-hidden", "true");
                return;
            }

            $wrap.show().removeAttr("aria-hidden");

            var win = $iframe[0].contentWindow;
            if (!win || typeof win.ucmmInitScheduleCountdown !== "function") {
                return;
            }

            var startSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_start]");
            var endSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_end]");
            var start = startSetting ? startSetting.get() : "";
            var end = endSetting ? endSetting.get() : "";

            ucmmGetScheduleUtcOffset();

            win.ucmmInitScheduleCountdown({
                start: start || "",
                end: end || "",
                isCustomizer: true
            });
        }

        wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_start]", function(value) {
            value.bind(function() {
                ucmmSyncScheduleEndMin();
                ucmmValidateScheduleRange();
                ucmmApplySchedulePreview();
            });
        });

        wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_end]", function(value) {
            value.bind(function() {
                ucmmValidateScheduleRange();
                ucmmApplySchedulePreview();
            });
        });

        // Show Schedule Date and time
        wp.customize("ucmm_wpbrigade_customization[ucmm_schedule_show_end_time]", function(value) {
            value.bind(function() {
                ucmmApplySchedulePreview();
            });
        });

        $(document).on(
            "input change",
            "#customize-control-ucmm_wpbrigade_customization-ucmm_schedule_start input[type='datetime-local'], #customize-control-ucmm_wpbrigade_customization-ucmm_schedule_end input[type='datetime-local']",
            function() {
                ucmmSyncScheduleEndMin();
                ucmmValidateScheduleRange();
                ucmmApplySchedulePreview();
            }
        );

        function ucmmSyncLayoutBodyClasses() {
            var $body = $("#customize-preview iframe").contents().find("body");
            var $logoImg = $body.find(".ucmm-logo img");

            if ($logoImg.length && $logoImg.is(":visible")) {
                $body.addClass("ucmm-has-logo");
            } else {
                $body.removeClass("ucmm-has-logo");
            }

            var $footer = $body.find(".footer-love");
            if ($footer.length && $footer.is(":visible")) {
                $body.addClass("ucmm-has-footer-love");
            } else {
                $body.removeClass("ucmm-has-footer-love");
            }

            $body.removeClass("ucmm-love-pos-left ucmm-love-pos-right ucmm-love-pos-center");
            if ($footer.length && $footer.is(":visible")) {
                var lovePosSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_display_footer_text_position]");
                var lovePos = lovePosSetting ? lovePosSetting.get() : "right";
                if (lovePos !== "left" && lovePos !== "right" && lovePos !== "center") {
                    lovePos = "right";
                }
                $body.addClass("ucmm-love-pos-" + lovePos);
            }
        }

        function ucmmApplySocialPositionClass(newval) {
            var $body = $("#customize-preview iframe").contents().find("body");
            $body.removeClass(
                "ucmm-social-pos-top ucmm-social-pos-right ucmm-social-pos-bottom ucmm-social-pos-left"
            );
            var slug = newval;
            if (slug !== "top" && slug !== "right" && slug !== "bottom" && slug !== "left") {
                slug = "bottom";
            }
            $body.addClass("ucmm-social-pos-" + slug);
            ucmmSyncLayoutBodyClasses();
        }
        wp.customize("ucmm_wpbrigade_customization[ucmm_social_icons_position]", function(value) {
            value.bind(function(newval) {
                ucmmApplySocialPositionClass(newval);
            });
        });

        function ucmmApplySocialIconsStyle(style) {
            var $body = $("#customize-preview iframe").contents().find("body");
            $body.removeClass("ucmm-social-style-classic ucmm-social-style-new");
            if (style !== "classic" && style !== "new") {
                style = "classic";
            }
            $body.addClass("ucmm-social-style-" + style);
        }
        wp.customize("ucmm_wpbrigade_customization[ucmm_social_icons_style]", function(value) {
            value.bind(function(newval) {
                ucmmApplySocialIconsStyle(newval);
            });
        });

        function ucmmSocKeyToIconClass(key) {
            if (!key || typeof key !== "string" || key.indexOf("ucmm_") !== 0) {
                return null;
            }
            var slug = key.replace(/^ucmm_/, "");
            return "ucmm-" + slug + "-icon";
        }

        function ucmmApplySocialIconsOrder(csv) {
            var $wrap = $("#customize-preview iframe").contents().find(".ucmm-social-icons");
            if (!$wrap.length) {
                return;
            }
            var keys = (csv || "").split(",");
            keys.forEach(function(k) {
                k = $.trim(k);
                var cls = ucmmSocKeyToIconClass(k);
                if (!cls) {
                    return;
                }
                var $a = $wrap.children("a." + cls);
                if ($a.length) {
                    $wrap.append($a);
                }
            });
        }

        wp.customize("ucmm_wpbrigade_customization[ucmm_social_icons_order]", function(value) {
            value.bind(function(newval) {
                ucmmApplySocialIconsOrder(newval);
            });
        });

        if ($.fn.sortable && $(".ucmm-social-order-list").length) {
            $(".ucmm-social-order-list").each(function() {
                var $list = $(this);
                var $inp = $list.prev(".ucmm-social-order-input");
                if (!$inp.length) {
                    return;
                }
                $list.sortable({
                    axis: "y",
                    handle: ".ucmm-social-order-handle",
                    items: "> li",
                    update: function() {
                        var keys = [];
                        $list.find("li.ucmm-social-order-item").each(function() {
                            var n = $(this).attr("data-network");
                            if (n) {
                                keys.push(n);
                            }
                        });
                        var csv = keys.join(",");
                        $inp.val(csv);
                        wp.customize("ucmm_wpbrigade_customization[ucmm_social_icons_order]").set(csv);
                        ucmmApplySocialIconsOrder(csv);
                    }
                });
            });
        }

        // Social URLs: sync href + visibility in preview (clicks handled in iframe script).
        $.each(ucmmSocialPreviewMap, function(settingKey) {
            wp.customize("ucmm_wpbrigade_customization[" + settingKey + "]", function(value) {
                value.bind(function(newval) {
                    ucmmUpdateSocialPreview(settingKey, newval);
                });
            });
        });

        wp.customize.previewer.bind("ready", function() {
            ucmmGetScheduleUtcOffset();
            ucmmInitSocialPreview();
            ucmmApplyBackground();
            ucmmApplyVectorImage();
            ucmmApplyLogoSize();
            ucmmApplyLoveTextColor();
            ucmmSyncLayoutBodyClasses();
            var styleSetting = wp.customize("ucmm_wpbrigade_customization[ucmm_social_icons_style]");
            if (styleSetting) {
                ucmmApplySocialIconsStyle(styleSetting.get());
            }
            ucmmSyncScheduleEndMin();
            ucmmValidateScheduleRange();
            ucmmApplySchedulePreview();
        });
    });

    function ucmmInitGroupHeadings() {
        $("#customize-controls h3.ucmm-group-heading").each(function() {
            if ($(this).next(".ucmm-group-info").length > 0) {
                $(this).next(".ucmm-group-info").hide();
                $(this).append(
                    '<button type="button" class="customize-help-toggle dashicons dashicons-editor-help" aria-expanded="false"><span class="screen-reader-text">Help</span></button>'
                );
            }
        });

        $(document).on(
            "click",
            "#customize-controls h3.ucmm-group-heading .customize-help-toggle",
            function() {
                $(this).parent().next(".ucmm-group-info").slideToggle();
            }
        );
    }

    wp.customize.bind("ready", function() {
        ucmmInitGroupHeadings();

        if (UCMM.autoFocusPanel && !(navigator.userAgent.toLowerCase().indexOf("firefox") > -1)) {
            wp.customize.panel("ucmm_wpbrigade_panel").focus();
        }
    });
})(jQuery);
